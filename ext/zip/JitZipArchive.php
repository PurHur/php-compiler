<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ext\standard\JitFileGetContents;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Builtin\StreamIoRuntime;
use PHPCompiler\JIT\Builtin\StreamLifecycleRuntime;
use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Builtin\StringFilePutContents;
use PHPCompiler\JIT\Builtin\ZipArchiveEmbedBridge;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for ZipArchive open/add/close/get/locate/index/rename/extract/comment
 * /isCompressionMethodSupported/isEncryptionMethodSupported/setPassword/statName/statIndex
 * (#35424 / #35437 / #35440 / #35449 / #35450 / #35465 / #35467 / #35472 / #35476 / #35486 /
 * #35498 / #35500 / #35504).
 *
 * php-src: ext/zip/php_zip.c — zim_ZipArchive_*
 */
final class JitZipArchive
{
    private static int $serial = 0;

    public static function open(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::open', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::ensureHandle($context, $obj);
        $path = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::open',
            0,
            'filename'
        );
        $i64 = $context->getTypeFromString('int64');
        $flags = $i64->constInt(0, false);
        if (isset($args[2])) {
            $flags = JitLongArg::lower($context, $args[2], 'ZipArchive::open(): Argument #2 ($flags)');
            if ($flags->typeOf() !== $i64) {
                $flags = $context->builder->sext($flags, $i64);
            }
        }

        // Probe path: string contents ⇒ exists; non-string ⇒ missing (CREATE path).
        $contentsPtr = JitFileGetContents::invoke($context, $path);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($contentsPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $stringTag = $i8->constInt(JITVariable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTag);
        // Allocate before branchIf — IR after a terminator breaks fgc_done (#35424).
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $zero = $i64->constInt(0, false);

        $id = (string) (++self::$serial);
        $missBlock = BasicBlockHelper::append($context, 'zip_open_miss_'.$id);
        $hitBlock = BasicBlockHelper::append($context, 'zip_open_hit_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_open_done_'.$id);
        $context->builder->branchIf($isString, $hitBlock, $missBlock);

        $context->builder->positionAtEnd($missBlock);
        // CREATE when file missing — flags checked in PHP userland via ZipArchive::CREATE.
        $createFlag = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(ZipArchiveConstants::CREATE, false)),
            $zero
        );
        $idC = (string) (++self::$serial);
        $createOk = BasicBlockHelper::append($context, 'zip_open_create_'.$idC);
        $createFail = BasicBlockHelper::append($context, 'zip_open_nocreate_'.$idC);
        $missJoin = BasicBlockHelper::append($context, 'zip_open_miss_join_'.$idC);
        $context->builder->branchIf($createFlag, $createOk, $createFail);

        $context->builder->positionAtEnd($createOk);
        $rcCreate = self::execLong(
            $context,
            'open_create',
            $handle,
            $zero,
            $empty,
            $empty
        );
        $createOkTail = $context->builder->getInsertBlock();
        $context->builder->branch($missJoin);

        $context->builder->positionAtEnd($createFail);
        $rcNoCreate = $i64->constInt(-ZipArchiveConstants::ER_NOENT, true);
        $createFailTail = $context->builder->getInsertBlock();
        $context->builder->branch($missJoin);

        $context->builder->positionAtEnd($missJoin);
        $rcMiss = $context->builder->phi($i64);
        $rcMiss->addIncoming($rcCreate, $createOkTail);
        $rcMiss->addIncoming($rcNoCreate, $createFailTail);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($hitBlock);
        $bytes = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $contentsPtr
        );
        $overwrite = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(ZipArchiveConstants::OVERWRITE, false)),
            $zero
        );
        $idO = (string) (++self::$serial);
        $owBlock = BasicBlockHelper::append($context, 'zip_open_ow_'.$idO);
        $rdBlock = BasicBlockHelper::append($context, 'zip_open_rd_'.$idO);
        $hitJoin = BasicBlockHelper::append($context, 'zip_open_hit_join_'.$idO);
        $context->builder->branchIf($overwrite, $owBlock, $rdBlock);

        $context->builder->positionAtEnd($owBlock);
        $rcOw = self::execLong(
            $context,
            "open_create",
            $handle,
            $zero,
            $empty,
            $empty
        );
        $owTail = $context->builder->getInsertBlock();
        $context->builder->branch($hitJoin);

        $context->builder->positionAtEnd($rdBlock);
        $rcRd = self::execLong(
            $context,
            "open_read",
            $handle,
            $zero,
            $bytes,
            $empty
        );
        $rdTail = $context->builder->getInsertBlock();
        $context->builder->branch($hitJoin);

        $context->builder->positionAtEnd($hitJoin);
        $rcHit = $context->builder->phi($i64);
        $rcHit->addIncoming($rcOw, $owTail);
        $rcHit->addIncoming($rcRd, $rdTail);
        $hitTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $rc = $context->builder->phi($i64);
        $rc->addIncoming($rcMiss, $missTail);
        $rc->addIncoming($rcHit, $hitTail);

        $isOk = $context->builder->icmp(Builder::INT_SGT, $rc, $zero);
        $id2 = (string) (++self::$serial);
        $okBlock = BasicBlockHelper::append($context, 'zip_open_store_'.$id2);
        $errBlock = BasicBlockHelper::append($context, 'zip_open_err_'.$id2);
        $retBlock = BasicBlockHelper::append($context, 'zip_open_ret2_'.$id2);
        $context->builder->branchIf($isOk, $okBlock, $errBlock);

        $context->builder->positionAtEnd($okBlock);
        self::storeHandle($context, $obj, $rc);
        // Keep path on the object — NestedJIT string args were truncated (#35424).
        self::storeValueStringProperty($context, $obj, VmZipArchive::PROP_FILENAME, $path);
        self::syncProps($context, $obj, $rc);
        $trueSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $trueSlot,
            $context->getTypeFromString('int1')->constInt(1, false)
        );
        $truePtr = JitValueBox::pointer($context, $trueSlot);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($retBlock);

        $context->builder->positionAtEnd($errBlock);
        $errCode = $context->builder->sub($zero, $rc);
        self::syncProps($context, $obj, $handle);
        $errSlot = JitValueBox::alloc($context);
        $errPtr = JitValueBox::pointer($context, $errSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $errPtr,
            $errCode
        );
        $errTail = $context->builder->getInsertBlock();
        $context->builder->branch($retBlock);

        $context->builder->positionAtEnd($retBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($truePtr, $okTail);
        $phi->addIncoming($errPtr, $errTail);

        return $phi;
    }

    public static function addFromString(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::addFromString', 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::addFromString',
            0,
            'name'
        );
        $content = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[2],
            'ZipArchive::addFromString',
            1,
            'content'
        );
        $packed = JitNestedHelperCoerce::callHelper(
            $context,
            ZipArchiveEmbedBridge::helperFunction($context, ZipArchiveEmbedBridge::addEntryHelper()),
            [$name, $content]
        );
        $ok = self::int32LeFromString(
            $context,
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $packed)
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /** ZipArchive::addEmptyDir — IR append "/" + NestedJIT addir (#35465 leftover of #35424 / #19880). */
    public static function addEmptyDir(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::addEmptyDir', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $dirname = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::addEmptyDir',
            0,
            'dirname'
        );
        // Optional $flags ignored (php-src zim_ZipArchive_addEmptyDir).
        // Empty dirname → false (do not concat to "/" — that would add a root entry).
        $strMap = $context->structFieldMap['__string__'];
        $dirLen = $context->builder->load(
            $context->builder->structGep($dirname, $strMap['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $dirLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_aed_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_aed_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_aed_done_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $rcEmpty = $zero;
        $emptyTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $slash = ZipArchiveEmbedBridge::opString($context, '/');
        $dirnameSlash = JitStringConcat::concat($context, $dirname, $slash, false);
        $packedOk = JitNestedHelperCoerce::callHelper(
            $context,
            ZipArchiveEmbedBridge::helperFunction($context, ZipArchiveEmbedBridge::addEmptyDirHelper()),
            [$dirnameSlash]
        );
        $rcOk = self::int32LeFromString(
            $context,
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $packedOk)
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $rcPhi = $context->builder->phi($i64);
        $rcPhi->addIncoming($rcOk, $okTail);
        $rcPhi->addIncoming($rcEmpty, $emptyTail);
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $rcPhi);
    }

    /** ZipArchive::addFile — file_get_contents in IR + NestedJIT add (#35449 leftover of #35424). */
    public static function addFile(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::addFile', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $filepath = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::addFile',
            0,
            'filepath'
        );
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $hasEntryname = isset($args[2]);
        $entrynameArg = $empty;
        if ($hasEntryname) {
            $entrynameArg = JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[2],
                'ZipArchive::addFile',
                1,
                'entryname'
            );
        }

        // Probe path like open() — string contents ⇒ readable file.
        $contentsPtr = JitFileGetContents::invoke($context, $filepath);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($contentsPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $stringTag = $i8->constInt(JITVariable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTag);
        $zero = $i64->constInt(0, false);

        $id = (string) (++self::$serial);
        $okBlock = BasicBlockHelper::append($context, 'zip_af_ok_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_af_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_af_done_'.$id);
        $context->builder->branchIf($isString, $okBlock, $missBlock);

        $context->builder->positionAtEnd($missBlock);
        $rcMiss = self::execLong($context, 'fail_noent', $handle, $zero, $empty, $empty);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $content = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $contentsPtr
        );
        if ($hasEntryname) {
            $name = $entrynameArg;
        } else {
            [, $name] = self::execLongAndPayload(
                $context,
                'basename',
                $handle,
                $zero,
                $filepath,
                $empty
            );
        }
        $packed = JitNestedHelperCoerce::callHelper(
            $context,
            ZipArchiveEmbedBridge::helperFunction($context, ZipArchiveEmbedBridge::addEntryHelper()),
            [$name, $content]
        );
        $rcOk = self::int32LeFromString(
            $context,
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $packed)
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $rcPhi = $context->builder->phi($i64);
        $rcPhi->addIncoming($rcOk, $okTail);
        $rcPhi->addIncoming($rcMiss, $missTail);
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $rcPhi);
    }

    public static function getFromName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::getFromName', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::getFromName',
            0,
            'name'
        );
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        [$found, $data] = self::execLongAndPayload(
            $context,
            'get',
            $handle,
            $context->getTypeFromString('int64')->constInt(0, false),
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        $i64 = $context->getTypeFromString('int64');
        $isFound = $context->builder->icmp(
            Builder::INT_NE,
            $found,
            $i64->constInt(0, false)
        );
        $id = (string) (++self::$serial);
        $okBlock = BasicBlockHelper::append($context, 'zip_gfn_ok_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_gfn_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_gfn_done_'.$id);
        $context->builder->branchIf($isFound, $okBlock, $missBlock);

        $context->builder->positionAtEnd($okBlock);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $okPtr,
            $data
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $missSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $missSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $missPtr = JitValueBox::pointer($context, $missSlot);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($okPtr, $okTail);
        $phi->addIncoming($missPtr, $missTail);

        return $phi;
    }

    public static function locateName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::locateName', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::locateName',
            0,
            'name'
        );
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $idx = self::execLong(
            $context,
            'locate',
            $handle,
            $context->getTypeFromString('int64')->constInt(0, false),
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxLongOrFalseFromI64($context, $idx);
    }

    public static function getFromIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::getFromIndex', 1, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower($context, $args[1], 'ZipArchive::getFromIndex(): Argument #1 ($index)');
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        [$found, $data] = self::execLongAndPayload(
            $context,
            'get_index',
            $index,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        $isFound = $context->builder->icmp(
            Builder::INT_NE,
            $found,
            $i64->constInt(0, false)
        );
        $id = (string) (++self::$serial);
        $okBlock = BasicBlockHelper::append($context, 'zip_gfi_ok_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_gfi_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_gfi_done_'.$id);
        $context->builder->branchIf($isFound, $okBlock, $missBlock);

        $context->builder->positionAtEnd($okBlock);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $okPtr,
            $data
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $missSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $missSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $missPtr = JitValueBox::pointer($context, $missSlot);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($okPtr, $okTail);
        $phi->addIncoming($missPtr, $missTail);

        return $phi;
    }

    public static function getNameIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::getNameIndex', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower($context, $args[1], 'ZipArchive::getNameIndex(): Argument #1 ($index)');
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        // Index is $a (same ABI as get_index / #35437).
        [$found, $data] = self::execLongAndPayload(
            $context,
            'name_index',
            $index,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        $isFound = $context->builder->icmp(
            Builder::INT_NE,
            $found,
            $i64->constInt(0, false)
        );
        $id = (string) (++self::$serial);
        $okBlock = BasicBlockHelper::append($context, 'zip_gni_ok_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_gni_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_gni_done_'.$id);
        $context->builder->branchIf($isFound, $okBlock, $missBlock);

        $context->builder->positionAtEnd($okBlock);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $okPtr,
            $data
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $missSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $missSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $missPtr = JitValueBox::pointer($context, $missSlot);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($okPtr, $okTail);
        $phi->addIncoming($missPtr, $missTail);

        return $phi;
    }

    public static function renameName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::renameName', 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::renameName',
            0,
            'name'
        );
        $newName = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[2],
            'ZipArchive::renameName',
            1,
            'new_name'
        );
        // Empty new_name → ValueError in IR (#35481; NestedJIT throw SIGSEGVs under thin AOT).
        $strMap = $context->structFieldMap['__string__'];
        $newLen = $context->builder->load(
            $context->builder->structGep($newName, $strMap['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $newLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_rn_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_rn_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::renameName(): Argument #2 ($new_name) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        $ok = self::execLong(
            $context,
            'rename',
            $handle,
            $zero,
            $name,
            $newName
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /** ZipArchive::renameIndex — NestedJIT rename_index for slots 0/1 (#35472 leftover of #35450). */
    public static function renameIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::renameIndex', 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower($context, $args[1], 'ZipArchive::renameIndex(): Argument #1 ($index)');
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $newName = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[2],
            'ZipArchive::renameIndex',
            1,
            'new_name'
        );
        // Empty new_name → ValueError in IR (#35481; NestedJIT throw SIGSEGVs under thin AOT).
        $strMap = $context->structFieldMap['__string__'];
        $newLen = $context->builder->load(
            $context->builder->structGep($newName, $strMap['length'])
        );
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $newLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_ri_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_ri_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::renameIndex(): Argument #2 ($new_name) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'rename_index',
            $index,
            $zero,
            $empty,
            $newName
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    public static function deleteName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::deleteName', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::deleteName',
            0,
            'name'
        );
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'delete',
            $handle,
            $context->getTypeFromString('int64')->constInt(0, false),
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /** ZipArchive::deleteIndex — NestedJIT delete_index for slot 0 (#35455 leftover of #35450). */
    public static function deleteIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::deleteIndex', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower($context, $args[1], 'ZipArchive::deleteIndex(): Argument #1 ($index)');
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'delete_index',
            $index,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::setArchiveComment — NestedJIT set_archive_comment (#35476 leftover of #35472).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setArchiveComment
     */
    public static function setArchiveComment(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::setArchiveComment', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $comment = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::setArchiveComment',
            0,
            'comment'
        );
        $packed = JitNestedHelperCoerce::callHelper(
            $context,
            ZipArchiveEmbedBridge::helperFunction($context, ZipArchiveEmbedBridge::setArchiveCommentHelper()),
            [$comment]
        );
        $ok = self::int32LeFromString(
            $context,
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $packed)
        );
        self::syncProps($context, $obj, $handle);
        self::storeValueStringProperty($context, $obj, VmZipArchive::PROP_COMMENT, $comment);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::getArchiveComment — NestedJIT get_archive_comment (#35476 leftover of #35472).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_getArchiveComment
     * Empty comment → false (libzip NULL).
     */
    public static function getArchiveComment(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::getArchiveComment', 0, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $packed = JitNestedHelperCoerce::callHelper(
            $context,
            ZipArchiveEmbedBridge::helperFunction($context, ZipArchiveEmbedBridge::getArchiveCommentHelper()),
            []
        );
        $packedPtr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $packed);
        $found = self::int32LeFromString($context, $packedPtr);
        $data = self::payloadFromPacked($context, $packedPtr);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        self::syncProps($context, $obj, $handle);

        $isFound = $context->builder->icmp(
            Builder::INT_NE,
            $found,
            $i64->constInt(0, false)
        );
        $id = (string) (++self::$serial);
        $okBlock = BasicBlockHelper::append($context, 'zip_gac_ok_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_gac_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_gac_done_'.$id);
        $context->builder->branchIf($isFound, $okBlock, $missBlock);

        $context->builder->positionAtEnd($okBlock);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $okPtr,
            $data
        );
        self::storeValueStringProperty($context, $obj, VmZipArchive::PROP_COMMENT, $data);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $missSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $missSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $missPtr = JitValueBox::pointer($context, $missSlot);
        self::storeValueStringProperty($context, $obj, VmZipArchive::PROP_COMMENT, $empty);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($okPtr, $okTail);
        $phi->addIncoming($missPtr, $missTail);

        return $phi;
    }

    /**
     * ZipArchive::setCommentName — NestedJIT scn (#35486 leftover of #35476).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setCommentName
     */
    public static function setCommentName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::setCommentName', 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::setCommentName',
            0,
            'name'
        );
        $comment = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[2],
            'ZipArchive::setCommentName',
            1,
            'comment'
        );
        // Empty name → ValueError in IR (#35486; NestedJIT throw SIGSEGVs — peer #35481).
        $strMap = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_scn_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_scn_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::setCommentName(): Argument #1 ($name) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        $ok = self::execLong(
            $context,
            'scn',
            $handle,
            $zero,
            $name,
            $comment
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::getCommentName — NestedJIT gcn (#35486 leftover of #35476).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_getCommentName
     * Miss → false; empty comment on hit → "" (unlike getArchiveComment).
     */
    public static function getCommentName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::getCommentName', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::getCommentName',
            0,
            'name'
        );
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $strMap = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_gcn_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_gcn_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::getCommentName(): Argument #1 ($name) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        [$found, $data] = self::execLongAndPayload(
            $context,
            'gcn',
            $handle,
            $zero,
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        $isFound = $context->builder->icmp(Builder::INT_NE, $found, $zero);
        $hitBlock = BasicBlockHelper::append($context, 'zip_gcn_hit_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_gcn_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_gcn_done_'.$id);
        $context->builder->branchIf($isFound, $hitBlock, $missBlock);

        $context->builder->positionAtEnd($hitBlock);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $okPtr,
            $data
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $missSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $missSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $missPtr = JitValueBox::pointer($context, $missSlot);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($okPtr, $okTail);
        $phi->addIncoming($missPtr, $missTail);

        return $phi;
    }

    /**
     * ZipArchive::setCommentIndex — NestedJIT sci (#35486 leftover of #35476).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setCommentIndex
     */
    public static function setCommentIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::setCommentIndex', 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower($context, $args[1], 'ZipArchive::setCommentIndex(): Argument #1 ($index)');
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $comment = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[2],
            'ZipArchive::setCommentIndex',
            1,
            'comment'
        );
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'sci',
            $index,
            $i64->constInt(0, false),
            $comment,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::getCommentIndex — NestedJIT gci (#35486 leftover of #35476).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_getCommentIndex
     * Invalid index → false; empty comment on hit → "".
     */
    public static function getCommentIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::getCommentIndex', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower($context, $args[1], 'ZipArchive::getCommentIndex(): Argument #1 ($index)');
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        [$found, $data] = self::execLongAndPayload(
            $context,
            'gci',
            $index,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        $zero = $i64->constInt(0, false);
        $isFound = $context->builder->icmp(Builder::INT_NE, $found, $zero);
        $id = (string) (++self::$serial);
        $hitBlock = BasicBlockHelper::append($context, 'zip_gci_hit_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_gci_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_gci_done_'.$id);
        $context->builder->branchIf($isFound, $hitBlock, $missBlock);

        $context->builder->positionAtEnd($hitBlock);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $okPtr,
            $data
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $missSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $missSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $missPtr = JitValueBox::pointer($context, $missSlot);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($okPtr, $okTail);
        $phi->addIncoming($missPtr, $missTail);

        return $phi;
    }

    /**
     * ZipArchive::unchangeAll — NestedJIT ua (#35489 leftover of #35486 / #20387).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_unchangeAll
     */
    public static function unchangeAll(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::unchangeAll', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        [$ok, $comment] = self::execLongAndPayload(
            $context,
            'ua',
            $handle,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);
        self::storeValueStringProperty($context, $obj, VmZipArchive::PROP_COMMENT, $comment);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::unchangeArchive — NestedJIT uar (#35489 leftover of #35486 / #20387).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_unchangeArchive
     */
    public static function unchangeArchive(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::unchangeArchive', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        [$ok, $comment] = self::execLongAndPayload(
            $context,
            'uar',
            $handle,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);
        self::storeValueStringProperty($context, $obj, VmZipArchive::PROP_COMMENT, $comment);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::unchangeIndex — NestedJIT uci (#35491 leftover of #35486).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_unchangeIndex
     */
    public static function unchangeIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::unchangeIndex', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower($context, $args[1], 'ZipArchive::unchangeIndex(): Argument #1 ($index)');
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'uci',
            $index,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::unchangeName — NestedJIT ucn (#35491 leftover of #35486).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_unchangeName
     */
    public static function unchangeName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::unchangeName', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::unchangeName',
            0,
            'name'
        );
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        $ok = self::execLong(
            $context,
            'ucn',
            $handle,
            $i64->constInt(0, false),
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::replaceFile — file_get_contents in IR + NestedJIT rpl (#35496 leftover of #35489).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_replaceFile
     * Optional $start/$length/$flags accepted for arity; slice/flags deferred (full-file replace).
     */
    public static function replaceFile(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::replaceFile', 2, 5)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $filepath = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::replaceFile',
            0,
            'filepath'
        );
        $index = JitLongArg::lower($context, $args[2], 'ZipArchive::replaceFile(): Argument #2 ($index)');
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $zero = $i64->constInt(0, false);
        $id = (string) (++self::$serial);

        // Empty filepath → ValueError in IR (#35481 peer; NestedJIT throw SIGSEGVs).
        $strMap = $context->structFieldMap['__string__'];
        $pathLen = $context->builder->load(
            $context->builder->structGep($filepath, $strMap['length'])
        );
        $isEmptyPath = $context->builder->icmp(Builder::INT_EQ, $pathLen, $zero);
        $emptyPathBlock = BasicBlockHelper::append($context, 'zip_rpl_empty_'.$id);
        $negCheckBlock = BasicBlockHelper::append($context, 'zip_rpl_negchk_'.$id);
        $context->builder->branchIf($isEmptyPath, $emptyPathBlock, $negCheckBlock);

        $context->builder->positionAtEnd($emptyPathBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::replaceFile(): Argument #1 ($filepath) must not be empty'
        );

        // Negative index → ValueError (php-src / VmZipArchive).
        $context->builder->positionAtEnd($negCheckBlock);
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $index, $zero);
        $negErrBlock = BasicBlockHelper::append($context, 'zip_rpl_neg_'.$id);
        $probeBlock = BasicBlockHelper::append($context, 'zip_rpl_probe_'.$id);
        $context->builder->branchIf($isNeg, $negErrBlock, $probeBlock);

        $context->builder->positionAtEnd($negErrBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::replaceFile(): Argument #2 ($index) must be greater than or equal to 0'
        );

        // Probe path like addFile() — string contents ⇒ readable file.
        $context->builder->positionAtEnd($probeBlock);
        $contentsPtr = JitFileGetContents::invoke($context, $filepath);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($contentsPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $stringTag = $i8->constInt(JITVariable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTag);

        $okBlock = BasicBlockHelper::append($context, 'zip_rpl_ok_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_rpl_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_rpl_done_'.$id);
        $context->builder->branchIf($isString, $okBlock, $missBlock);

        $context->builder->positionAtEnd($missBlock);
        $rcMiss = self::execLong($context, 'fail_noent', $handle, $zero, $empty, $empty);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $content = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $contentsPtr
        );
        $packed = JitNestedHelperCoerce::callHelper(
            $context,
            ZipArchiveEmbedBridge::helperFunction($context, ZipArchiveEmbedBridge::replaceEntryHelper()),
            [$index, $content]
        );
        $rcOk = self::int32LeFromString(
            $context,
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $packed)
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $rcPhi = $context->builder->phi($i64);
        $rcPhi->addIncoming($rcOk, $okTail);
        $rcPhi->addIncoming($rcMiss, $missTail);
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $rcPhi);
    }

    /**
     * ZipArchive::setPassword — NestedJIT spw (#35500 leftover of #35496 / #19873).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setPassword
     */
    public static function setPassword(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::setPassword', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $password = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::setPassword',
            0,
            'password'
        );
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        $ok = self::execLong(
            $context,
            'spw',
            $handle,
            $i64->constInt(0, false),
            $password,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::statName — NestedJIT stn → RETURN_SB hashtable (#35504 leftover of #35500).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_statName
     */
    public static function statName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::statName', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::statName',
            0,
            'name'
        );
        $i64 = $context->getTypeFromString('int64');
        $strMap = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_stn_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_stn_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::statName(): Argument #1 ($name) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        [$found, $payload] = self::execLongAndPayload(
            $context,
            'stn',
            $handle,
            $zero,
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxStatOrFalse($context, $found, $payload);
    }

    /**
     * ZipArchive::statIndex — NestedJIT sti → RETURN_SB hashtable (#35504 leftover of #35500).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_statIndex
     */
    public static function statIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::statIndex', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower($context, $args[1], 'ZipArchive::statIndex(): Argument #1 ($index)');
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $zero = $i64->constInt(0, false);
        [$found, $payload] = self::execLongAndPayload(
            $context,
            'sti',
            $index,
            $zero,
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxStatOrFalse($context, $found, $payload);
    }

    /**
     * ZipArchive::setCompressionName — NestedJIT cpm (#35506 leftover of #35500 / #20363).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setCompressionName
     * Optional $compflags accepted and ignored (VmZipArchive).
     */
    public static function setCompressionName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::setCompressionName', 2, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::setCompressionName',
            0,
            'name'
        );
        $method = JitLongArg::lower(
            $context,
            $args[2],
            'ZipArchive::setCompressionName(): Argument #2 ($method)'
        );
        $i64 = $context->getTypeFromString('int64');
        if ($method->typeOf() !== $i64) {
            $method = $context->builder->sext($method, $i64);
        }
        // Empty name → ValueError in IR (#35481 peer; NestedJIT throw SIGSEGVs).
        $strMap = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_cpm_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_cpm_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::setCompressionName(): Argument #1 ($name) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'cpm',
            $method,
            $zero,
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::setCompressionIndex — NestedJIT cpi (#35506 leftover of #35500 / #20363).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setCompressionIndex
     * Optional $compflags accepted and ignored (VmZipArchive).
     */
    public static function setCompressionIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::setCompressionIndex', 2, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower(
            $context,
            $args[1],
            'ZipArchive::setCompressionIndex(): Argument #1 ($index)'
        );
        $method = JitLongArg::lower(
            $context,
            $args[2],
            'ZipArchive::setCompressionIndex(): Argument #2 ($method)'
        );
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        if ($method->typeOf() !== $i64) {
            $method = $context->builder->sext($method, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'cpi',
            $index,
            $method,
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::setEncryptionName — locate + NestedJIT sei/seip (#35503 leftover of #35500 / #19873).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setEncryptionName
     * Optional $password: omit → session password; "" → clear entry password.
     * Name lookup reuses locate (NestedJIT-safe; peer setEncryptionIndex).
     */
    public static function setEncryptionName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::setEncryptionName', 2, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::setEncryptionName',
            0,
            'name'
        );
        $method = JitLongArg::lower(
            $context,
            $args[2],
            'ZipArchive::setEncryptionName(): Argument #2 ($method)'
        );
        $i64 = $context->getTypeFromString('int64');
        if ($method->typeOf() !== $i64) {
            $method = $context->builder->sext($method, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $hasPassword = isset($args[3]);
        $password = $empty;
        if ($hasPassword) {
            $password = JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[3],
                'ZipArchive::setEncryptionName',
                2,
                'password'
            );
        }

        // Empty name → ValueError in IR (NestedJIT throw SIGSEGVs — peer #35481).
        $strMap = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_sen_empty_'.$id);
        $locateBlock = BasicBlockHelper::append($context, 'zip_sen_loc_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $locateBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::setEncryptionName(): Argument #1 ($name) must not be empty'
        );

        $context->builder->positionAtEnd($locateBlock);
        $idx = self::execLong(
            $context,
            'locate',
            $handle,
            $zero,
            $name,
            $empty
        );
        $isMiss = $context->builder->icmp(Builder::INT_SLT, $idx, $zero);
        $missBlock = BasicBlockHelper::append($context, 'zip_sen_miss_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_sen_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_sen_done_'.$id);
        $context->builder->branchIf($isMiss, $missBlock, $okBlock);

        $context->builder->positionAtEnd($missBlock);
        $missOk = $zero;
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $op = $hasPassword ? 'seip' : 'sei';
        $okRc = self::execLong(
            $context,
            $op,
            $idx,
            $method,
            $password,
            $empty
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $rcPhi = $context->builder->phi($i64);
        $rcPhi->addIncoming($okRc, $okTail);
        $rcPhi->addIncoming($missOk, $missTail);
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $rcPhi);
    }

    /**
     * ZipArchive::setEncryptionIndex — NestedJIT sei/seip (#35503 leftover of #35500 / #20378).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setEncryptionIndex
     * sei = omit password (session fallback); seip = password in $s1.
     */
    public static function setEncryptionIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::setEncryptionIndex', 2, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower(
            $context,
            $args[1],
            'ZipArchive::setEncryptionIndex(): Argument #1 ($index)'
        );
        $method = JitLongArg::lower(
            $context,
            $args[2],
            'ZipArchive::setEncryptionIndex(): Argument #2 ($method)'
        );
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        if ($method->typeOf() !== $i64) {
            $method = $context->builder->sext($method, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $hasPassword = isset($args[3]);
        $password = $empty;
        $op = 'sei';
        if ($hasPassword) {
            $op = 'seip';
            $password = JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[3],
                'ZipArchive::setEncryptionIndex',
                2,
                'password'
            );
        }
        $ok = self::execLong(
            $context,
            $op,
            $index,
            $method,
            $password,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }


    /**
     * ZipArchive::setMtimeName — NestedJIT smn (#35508 leftover of #35500 / #20363).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setMtimeName
     */
    public static function setMtimeName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::setMtimeName', 2, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::setMtimeName',
            0,
            'name'
        );
        $timestamp = JitLongArg::lower($context, $args[2], 'ZipArchive::setMtimeName(): Argument #2 ($timestamp)');
        $i64 = $context->getTypeFromString('int64');
        if ($timestamp->typeOf() !== $i64) {
            $timestamp = $context->builder->sext($timestamp, $i64);
        }
        // Empty name → ValueError in IR (#35508; NestedJIT throw SIGSEGVs — peer #35481).
        $strMap = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_smn_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_smn_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::setMtimeName(): Argument #1 ($name) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'smn',
            $handle,
            $timestamp,
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::setMtimeIndex — NestedJIT smi (#35508 leftover of #35500 / #20363).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setMtimeIndex
     */
    public static function setMtimeIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::setMtimeIndex', 2, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower($context, $args[1], 'ZipArchive::setMtimeIndex(): Argument #1 ($index)');
        $timestamp = JitLongArg::lower($context, $args[2], 'ZipArchive::setMtimeIndex(): Argument #2 ($timestamp)');
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        if ($timestamp->typeOf() !== $i64) {
            $timestamp = $context->builder->sext($timestamp, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'smi',
            $index,
            $timestamp,
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::setExternalAttributesName — NestedJIT ean (#35515 leftover of #35500 / #20363).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setExternalAttributesName
     */
    public static function setExternalAttributesName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::setExternalAttributesName', 3, 4)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::setExternalAttributesName',
            0,
            'name'
        );
        $opsys = JitLongArg::lower(
            $context,
            $args[2],
            'ZipArchive::setExternalAttributesName(): Argument #2 ($opsys)'
        );
        $attr = JitLongArg::lower(
            $context,
            $args[3],
            'ZipArchive::setExternalAttributesName(): Argument #3 ($attr)'
        );
        $i64 = $context->getTypeFromString('int64');
        if ($opsys->typeOf() !== $i64) {
            $opsys = $context->builder->sext($opsys, $i64);
        }
        if ($attr->typeOf() !== $i64) {
            $attr = $context->builder->sext($attr, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $strMap = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_ean_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_ean_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::setExternalAttributesName(): Argument #1 ($name) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        $ok = self::execLong(
            $context,
            'ean',
            $opsys,
            $attr,
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::setExternalAttributesIndex — name_index + ean (#35515 leftover of #35500 / #20363).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setExternalAttributesIndex
     * Resolves the entry name then reuses ean (NestedJIT ABI has only two int slots).
     */
    public static function setExternalAttributesIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::setExternalAttributesIndex', 3, 4)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower(
            $context,
            $args[1],
            'ZipArchive::setExternalAttributesIndex(): Argument #1 ($index)'
        );
        $opsys = JitLongArg::lower(
            $context,
            $args[2],
            'ZipArchive::setExternalAttributesIndex(): Argument #2 ($opsys)'
        );
        $attr = JitLongArg::lower(
            $context,
            $args[3],
            'ZipArchive::setExternalAttributesIndex(): Argument #3 ($attr)'
        );
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        if ($opsys->typeOf() !== $i64) {
            $opsys = $context->builder->sext($opsys, $i64);
        }
        if ($attr->typeOf() !== $i64) {
            $attr = $context->builder->sext($attr, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $zero = $i64->constInt(0, false);
        [$found, $name] = self::execLongAndPayload(
            $context,
            'name_index',
            $index,
            $zero,
            $empty,
            $empty
        );
        $isFound = $context->builder->icmp(Builder::INT_NE, $found, $zero);
        $id = (string) (++self::$serial);
        $missBlock = BasicBlockHelper::append($context, 'zip_eai_miss_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_eai_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_eai_done_'.$id);
        $context->builder->branchIf($isFound, $okBlock, $missBlock);

        $context->builder->positionAtEnd($missBlock);
        // Match VmZipArchive ER_INVAL on bad index.
        $missRc = self::execLong($context, 'fail_inval', $handle, $zero, $empty, $empty);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $okRc = self::execLong(
            $context,
            'ean',
            $opsys,
            $attr,
            $name,
            $empty
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $rcPhi = $context->builder->phi($i64);
        $rcPhi->addIncoming($okRc, $okTail);
        $rcPhi->addIncoming($missRc, $missTail);
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $rcPhi);
    }

    /**
     * ZipArchive::getExternalAttributesName — NestedJIT gan (#35527 leftover of #35522 / #20363).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_getExternalAttributesName
     * Writes &$opsys / &$attr; returns bool.
     */
    public static function getExternalAttributesName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::getExternalAttributesName', 3, 4)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::getExternalAttributesName',
            0,
            'name'
        );
        $strMap = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_gan_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_gan_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::getExternalAttributesName(): Argument #1 ($name) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        [$found, $payload] = self::execLongAndPayload(
            $context,
            'gan',
            $handle,
            $zero,
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxGetExternalAttr($context, $found, $payload, $args[2], $args[3]);
    }

    /**
     * ZipArchive::getExternalAttributesIndex — NestedJIT gai (#35527 leftover of #35522 / #20363).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_getExternalAttributesIndex
     */
    public static function getExternalAttributesIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::getExternalAttributesIndex', 3, 4)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower(
            $context,
            $args[1],
            'ZipArchive::getExternalAttributesIndex(): Argument #1 ($index)'
        );
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        [$found, $payload] = self::execLongAndPayload(
            $context,
            'gai',
            $index,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxGetExternalAttr($context, $found, $payload, $args[2], $args[3]);
    }

    /**
     * On hit: write opsys/attr into by-ref outs from payload (2×int32 LE); return true.
     * On miss: return false without touching outs.
     */
    private static function boxGetExternalAttr(
        Context $context,
        Value $foundI64,
        Value $payload,
        JITVariable $opsysOut,
        JITVariable $attrOut
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $isFound = $context->builder->icmp(Builder::INT_NE, $foundI64, $i64->constInt(0, false));
        $id = (string) (++self::$serial);
        $okBlock = BasicBlockHelper::append($context, 'zip_gea_ok_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_gea_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_gea_done_'.$id);
        $context->builder->branchIf($isFound, $okBlock, $missBlock);

        $context->builder->positionAtEnd($okBlock);
        $opsys = self::int32LeAtStringOffset($context, $payload, 0);
        $attr = self::int32LeAtStringOffset($context, $payload, 4);
        JitValueBox::writeLong($context, JitValueBox::valuePtrFromVariable($context, $opsysOut), $opsys);
        JitValueBox::writeLong($context, JitValueBox::valuePtrFromVariable($context, $attrOut), $attr);
        $okSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $okSlot,
            $context->getTypeFromString('int1')->constInt(1, false)
        );
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $missSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $missSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $missPtr = JitValueBox::pointer($context, $missSlot);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($okPtr, $okTail);
        $phi->addIncoming($missPtr, $missTail);

        return $phi;
    }

    public static function close(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::close', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        StringFilePutContents::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        // Filename lives on the object (set by open), not in NestedJIT state.
        $filename = self::loadFilenameString($context, $obj);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        [$ok, $bytes] = self::execLongAndPayload(
            $context,
            'close',
            $handle,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        $isOk = $context->builder->icmp(Builder::INT_NE, $ok, $i64->constInt(0, false));
        $id = (string) (++self::$serial);
        $writeBlock = BasicBlockHelper::append($context, 'zip_close_write_'.$id);
        $skipBlock = BasicBlockHelper::append($context, 'zip_close_skip_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_close_done_'.$id);
        $context->builder->branchIf($isOk, $writeBlock, $skipBlock);

        $context->builder->positionAtEnd($writeBlock);
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $bytes
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $filename,
            $dataOwned,
            $i64->constInt(0, false)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($skipBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::extractTo — NestedJIT extract + file_put_contents leaf (#35467 leftover of #35424).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_extractTo
     */
    public static function extractTo(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::extractTo', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        StringFilePutContents::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $pathto = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::extractTo',
            0,
            'pathto'
        );
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        $ok = self::execLong(
            $context,
            'extract',
            $handle,
            $i64->constInt(0, false),
            $pathto,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /** ZipArchive::getStatusString — NestedJIT status_string payload (#35449 leftover of #35424). */
    public static function getStatusString(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::getStatusString', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        [, $msg] = self::execLongAndPayload(
            $context,
            'status_string',
            $handle,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $msg
        );

        return $ptr;
    }

    /**
     * ZipArchive::count — Countable entry count via NestedJIT num_files (#35466 leftover of #35424).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_count
     */
    public static function count(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::count', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        $n = self::execLong(
            $context,
            'num_files',
            $handle,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $ptr,
            $n
        );

        return $ptr;
    }

    /** ZipArchive::isWritable — NestedJIT is_writable (#35478 leftover of #35424). */
    public static function isWritable(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::isWritable', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        $ok = self::execLong(
            $context,
            'is_writable',
            $handle,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /** ZipArchive::setReadOnly — NestedJIT set_readonly (#35478 leftover of #35424). */
    public static function setReadOnly(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::setReadOnly', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $flag = JitBoolArg::lower(
            $context,
            $args[1],
            'ZipArchive::setReadOnly(): Argument #1 ($readonly)'
        );
        $i64 = $context->getTypeFromString('int64');
        $flagI64 = $context->builder->zExt($flag, $i64);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'set_readonly',
            $flagI64,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::setArchiveFlag — NestedJIT saf (#35522 leftover of #35515 / #21831).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setArchiveFlag
     */
    public static function setArchiveFlag(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::setArchiveFlag', 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $flag = JitLongArg::lower($context, $args[1], 'ZipArchive::setArchiveFlag(): Argument #1 ($flag)');
        $value = JitLongArg::lower($context, $args[2], 'ZipArchive::setArchiveFlag(): Argument #2 ($value)');
        $i64 = $context->getTypeFromString('int64');
        if ($flag->typeOf() !== $i64) {
            $flag = $context->builder->sext($flag, $i64);
        }
        if ($value->typeOf() !== $i64) {
            $value = $context->builder->sext($value, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'saf',
            $flag,
            $value,
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::getArchiveFlag — NestedJIT gaf (#35522 leftover of #35515 / #21831).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_getArchiveFlag
     */
    public static function getArchiveFlag(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::getArchiveFlag', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $flag = JitLongArg::lower($context, $args[1], 'ZipArchive::getArchiveFlag(): Argument #1 ($flag)');
        $i64 = $context->getTypeFromString('int64');
        if ($flag->typeOf() !== $i64) {
            $flag = $context->builder->sext($flag, $i64);
        }
        $flags = $i64->constInt(0, false);
        if (isset($args[2])) {
            $flags = JitLongArg::lower($context, $args[2], 'ZipArchive::getArchiveFlag(): Argument #2 ($flags)');
            if ($flags->typeOf() !== $i64) {
                $flags = $context->builder->sext($flags, $i64);
            }
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $n = self::execLong(
            $context,
            'gaf',
            $flag,
            $flags,
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $ptr,
            $n
        );

        return $ptr;
    }

    /**
     * ZipArchive::clearError — NestedJIT ce (#35531 leftover of #35527 / #20378).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_clearError (void; resets status to ER_OK).
     */
    public static function clearError(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::clearError', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        self::execLong(
            $context,
            'ce',
            $handle,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return $ptr;
    }

    /**
     * ZipArchive::registerProgressCallback — NestedJIT rpc (#35539 leftover of #35534 / #20378).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_registerProgressCallback
     * Callable is accepted for arity/type; NestedJIT does not persist/invoke it yet.
     */
    public static function registerProgressCallback(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::registerProgressCallback', 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        // $rate + $callback accepted for arity; NestedJIT does not coerce/store them yet.
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        $ok = self::execLong(
            $context,
            'rpc',
            $handle,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::registerCancelCallback — NestedJIT rcc (#35539 leftover of #35534 / #20378).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_registerCancelCallback
     */
    public static function registerCancelCallback(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::registerCancelCallback', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        $ok = self::execLong(
            $context,
            'rcc',
            $handle,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /**
     * ZipArchive::getStream — NestedJIT gstr + php://memory fopen/fwrite/rewind (#35534 leftover of #35531).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_getStream
     */
    public static function getStream(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ZipArchive::getStream', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        StreamIoRuntime::ensureLinkedForUserScriptLowering($context);
        StreamLifecycleRuntime::ensureLinkedForUserScriptLowering($context);
        StreamReadRuntime::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::getStream',
            0,
            'name'
        );
        $i64 = $context->getTypeFromString('int64');
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $strMap = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nameLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_gstr_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_gstr_lookup_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_gstr_done_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptySlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $emptySlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $emptyPtr = JitValueBox::pointer($context, $emptySlot);
        $emptyTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        [$found, $data] = self::execLongAndPayload(
            $context,
            'gstr',
            $handle,
            $zero,
            $name,
            $empty
        );
        self::syncProps($context, $obj, $handle);
        $streamPtr = self::boxStreamFromPayload($context, $found, $data);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $ptrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($ptrTy);
        $phi->addIncoming($emptyPtr, $emptyTail);
        $phi->addIncoming($streamPtr, $okTail);

        return $phi;
    }

    /**
     * ZipArchive::getStreamIndex — NestedJIT gsi (#35534 leftover of #35531 / #20378).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_getStreamIndex
     */
    public static function getStreamIndex(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::getStreamIndex', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        StreamIoRuntime::ensureLinkedForUserScriptLowering($context);
        StreamLifecycleRuntime::ensureLinkedForUserScriptLowering($context);
        StreamReadRuntime::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $index = JitLongArg::lower(
            $context,
            $args[1],
            'ZipArchive::getStreamIndex(): Argument #1 ($index)'
        );
        $i64 = $context->getTypeFromString('int64');
        if ($index->typeOf() !== $i64) {
            $index = $context->builder->sext($index, $i64);
        }
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        [$found, $data] = self::execLongAndPayload(
            $context,
            'gsi',
            $index,
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxStreamFromPayload($context, $found, $data);
    }

    /**
     * ZipArchive::getStreamName — flags ignored; same as getStream (#35534 leftover of #35531 / #20378).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_getStreamName
     */
    public static function getStreamName(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::getStreamName', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        // Optional $flags ignored (VmZipArchive::getStreamName).
        return self::getStream($context, $args[0], $args[1]);
    }

    /**
     * ZipArchive::addGlob — NestedJIT ag + path-list array (#35537 leftover of #35531 / #20387).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_addGlob
     * Options array ignored in NestedJIT honest subset (empty options).
     */
    public static function addGlob(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::addGlob', 1, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $pattern = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::addGlob',
            0,
            'pattern'
        );
        $i64 = $context->getTypeFromString('int64');
        $flags = $i64->constInt(0, false);
        if (isset($args[2])) {
            $flags = JitLongArg::lower($context, $args[2], 'ZipArchive::addGlob(): Argument #2 ($flags)');
            if ($flags->typeOf() !== $i64) {
                $flags = $context->builder->sext($flags, $i64);
            }
        }
        $strMap = $context->structFieldMap['__string__'];
        $patLen = $context->builder->load(
            $context->builder->structGep($pattern, $strMap['length'])
        );
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $patLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_ag_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_ag_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::addGlob(): Argument #1 ($pattern) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        [$rc, $payload] = self::execLongAndPayload(
            $context,
            'ag',
            $flags,
            $zero,
            $pattern,
            $empty
        );
        self::syncProps($context, $obj, $handle);

        return self::boxPathListOrFalse($context, $rc, $payload);
    }

    /**
     * ZipArchive::addPattern — NestedJIT ap + path-list array (#35537 leftover of #35531 / #20387).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_addPattern
     * Options array ignored in NestedJIT honest subset (empty options).
     */
    public static function addPattern(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'ZipArchive::addPattern', 1, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        ZipArchiveEmbedBridge::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $pattern = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'ZipArchive::addPattern',
            0,
            'pattern'
        );
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $path = $empty;
        if (isset($args[2])) {
            $path = JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[2],
                'ZipArchive::addPattern',
                1,
                'path'
            );
        } else {
            // Default "." — php-src / VmZipArchive.
            $path = $context->builder->load($context->constantStringFromString('.'));
        }
        $i64 = $context->getTypeFromString('int64');
        $strMap = $context->structFieldMap['__string__'];
        $patLen = $context->builder->load(
            $context->builder->structGep($pattern, $strMap['length'])
        );
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $patLen, $zero);
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_ap_empty_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_ap_ok_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $okBlock);

        $context->builder->positionAtEnd($emptyBlock);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'ZipArchive::addPattern(): Argument #1 ($pattern) must not be empty'
        );

        $context->builder->positionAtEnd($okBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $matchArg = $pattern;
        $op = 'ap';
        // NestedJIT preg_match returns false on some escaped /…$/ forms; peel compile-time
        // anchored suffix literals to NestedJIT `aps` (str_ends_with) (#35537).
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
        if (\is_string($lit) && 1 === \preg_match('~^/((?:\\\\.|[^/$\\\\])+)\\$/[a-zA-Z]*$~', $lit, $mm)) {
            $op = 'aps';
            $suffix = \stripcslashes($mm[1]);
            $matchArg = $context->builder->load($context->constantStringFromString($suffix));
        }
        [$rc, $payload] = self::execLongAndPayload(
            $context,
            $op,
            $zero,
            $zero,
            $matchArg,
            $path
        );
        self::syncProps($context, $obj, $handle);

        return self::boxPathListOrFalse($context, $rc, $payload);
    }

    /**
     * ZipArchive::isCompressionMethodSupported — static pure IR (#35498 leftover of #35478 / #20363).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_isCompressionMethodSupported
     * Pure-PHP ZipEngine: CM_STORE / CM_DEFAULT only (enc ignored).
     */
    public static function isCompressionMethodSupported(Context $context, JITVariable ...$args): Value
    {
        if (!self::requireStaticArgCountRange(
            $context,
            $args,
            'ZipArchive::isCompressionMethodSupported',
            1,
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $method = JitLongArg::lower(
            $context,
            $args[0],
            'ZipArchive::isCompressionMethodSupported(): Argument #1 ($method)'
        );
        // Optional $enc is accepted and ignored (php-src / VmZipArchive).
        $i64 = $context->getTypeFromString('int64');
        $isStore = $context->builder->icmp(
            Builder::INT_EQ,
            $method,
            $i64->constInt(ZipArchiveConstants::CM_STORE, false)
        );
        $isDefault = $context->builder->icmp(
            Builder::INT_EQ,
            $method,
            $i64->constInt(ZipArchiveConstants::CM_DEFAULT, true)
        );
        $ok = $context->builder->or($isStore, $isDefault);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return JitValueBox::pointer($context, $slot);
    }

    /**
     * ZipArchive::isEncryptionMethodSupported — static pure IR (#35498 leftover of #35478 / #20378).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_isEncryptionMethodSupported
     * Pure-PHP ZipEngine: EM_NONE / TRAD_PKWARE / AES_128/192/256 (enc ignored).
     */
    public static function isEncryptionMethodSupported(Context $context, JITVariable ...$args): Value
    {
        if (!self::requireStaticArgCountRange(
            $context,
            $args,
            'ZipArchive::isEncryptionMethodSupported',
            1,
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $method = JitLongArg::lower(
            $context,
            $args[0],
            'ZipArchive::isEncryptionMethodSupported(): Argument #1 ($method)'
        );
        $i64 = $context->getTypeFromString('int64');
        $ok = $context->builder->icmp(
            Builder::INT_EQ,
            $method,
            $i64->constInt(ZipArchiveConstants::EM_NONE, false)
        );
        foreach ([
            ZipArchiveConstants::EM_TRAD_PKWARE,
            ZipArchiveConstants::EM_AES_128,
            ZipArchiveConstants::EM_AES_192,
            ZipArchiveConstants::EM_AES_256,
        ] as $code) {
            $eq = $context->builder->icmp(
                Builder::INT_EQ,
                $method,
                $i64->constInt($code, false)
            );
            $ok = $context->builder->or($ok, $eq);
        }
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return JitValueBox::pointer($context, $slot);
    }

    /**
     * Static ZipArchive method argc — no implicit $this (peer PDO::getAvailableDrivers / #35498).
     *
     * @param JITVariable[] $args
     */
    private static function requireStaticArgCountRange(
        Context $context,
        array $args,
        string $function,
        int $minimum,
        int $maximum
    ): bool {
        $given = \count($args);
        if ($given < $minimum) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::atLeastUserArgCountMessage($function, $minimum, $given)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_argc_cont');

            return false;
        }
        if ($given > $maximum) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::atMostUserArgCountMessage($function, $maximum, $given)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_argc_cont');

            return false;
        }

        return true;
    }

    public static function ensureHandle(Context $context, Value $obj): Value
    {
        ZipArchiveEmbedBridge::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $existing = self::loadHandle($context, $obj);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $existing, $i64->constInt(0, false));
        $id = (string) (++self::$serial);
        $allocBlock = BasicBlockHelper::append($context, 'zip_alloc_'.$id);
        $keepBlock = BasicBlockHelper::append($context, 'zip_keep_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_handle_done_'.$id);
        $context->builder->branchIf($isZero, $allocBlock, $keepBlock);

        $context->builder->positionAtEnd($allocBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $fresh = self::execLong(
            $context,
            "alloc",
            $i64->constInt(0, false),
            $i64->constInt(0, false),
            $empty,
            $empty
        );
        self::storeHandle($context, $obj, $fresh);
        $allocTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($keepBlock);
        $keepTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($fresh, $allocTail);
        $phi->addIncoming($existing, $keepTail);

        return $phi;
    }

    private static function execLong(
        Context $context,
        string $op,
        Value $a,
        Value $b,
        Value $s1,
        Value $s2
    ): Value {
        $packed = self::execPacked($context, $op, $a, $b, $s1, $s2);

        return self::int32LeFromString($context, $packed);
    }

    /** @return array{0: Value, 1: Value} [rc i64, payload __string__*] */
    private static function execLongAndPayload(
        Context $context,
        string $op,
        Value $a,
        Value $b,
        Value $s1,
        Value $s2
    ): array {
        $packed = self::execPacked($context, $op, $a, $b, $s1, $s2);
        $rc = self::int32LeFromString($context, $packed);
        $payload = self::payloadFromPacked($context, $packed);

        return [$rc, $payload];
    }

    private static function execPacked(
        Context $context,
        string $op,
        Value $a,
        Value $b,
        Value $s1,
        Value $s2
    ): Value {
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            ZipArchiveEmbedBridge::helperFunction($context, ZipArchiveEmbedBridge::execHelper()),
            [
                ZipArchiveEmbedBridge::opString($context, $op),
                $a,
                $b,
                $s1,
                $s2,
            ]
        );

        return JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
    }

    /** Decode 4-byte little-endian int32 from packed exec result into sext i64. */
    private static function int32LeFromString(Context $context, Value $strPtr): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $b0 = $context->builder->load($context->builder->gep($data, $context->constantFromInteger(0, 'size_t')));
        $b1 = $context->builder->load($context->builder->gep($data, $context->constantFromInteger(1, 'size_t')));
        $b2 = $context->builder->load($context->builder->gep($data, $context->constantFromInteger(2, 'size_t')));
        $b3 = $context->builder->load($context->builder->gep($data, $context->constantFromInteger(3, 'size_t')));
        $u0 = $context->builder->zext($b0, $i32);
        $u1 = $context->builder->shl($context->builder->zext($b1, $i32), $i32->constInt(8, false));
        $u2 = $context->builder->shl($context->builder->zext($b2, $i32), $i32->constInt(16, false));
        $u3 = $context->builder->shl($context->builder->zext($b3, $i32), $i32->constInt(24, false));
        $packed = $context->builder->or($context->builder->or($u0, $u1), $context->builder->or($u2, $u3));

        return $context->builder->sext($packed, $i64);
    }

    private static function payloadFromPacked(Context $context, Value $strPtr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $payLen = $context->builder->sub($len, $i64->constInt(4, false));
        $isEmpty = $context->builder->icmp(
            Builder::INT_SLE,
            $payLen,
            $i64->constInt(0, false)
        );
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_pay_empty_'.$id);
        $sliceBlock = BasicBlockHelper::append($context, 'zip_pay_slice_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_pay_done_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $sliceBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $emptyTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($sliceBlock);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $payCstr = $context->builder->gep($data, $context->constantFromInteger(4, 'size_t'));
        $sliced = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $payLen,
            $context->builder->pointerCast($payCstr, $context->getTypeFromString('char*'))
        );
        $sliceTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strTy);
        $phi->addIncoming($empty, $emptyTail);
        $phi->addIncoming($sliced, $sliceTail);

        return $phi;
    }

    private static function syncProps(Context $context, Value $obj, Value $handle): void
    {
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $status = self::execLong($context, "status", $handle, $zero, $empty, $empty);
        $statusSys = self::execLong($context, "status_sys", $handle, $zero, $empty, $empty);
        $lastId = self::execLong($context, "last_id", $handle, $zero, $empty, $empty);
        $numFiles = self::execLong($context, "num_files", $handle, $zero, $empty, $empty);
        self::storeNativeLongProperty($context, $obj, VmZipArchive::PROP_STATUS, $status);
        self::storeNativeLongProperty($context, $obj, VmZipArchive::PROP_STATUS_SYS, $statusSys);
        self::storeNativeLongProperty($context, $obj, VmZipArchive::PROP_LAST_ID, $lastId);
        self::storeNativeLongProperty($context, $obj, VmZipArchive::PROP_NUM_FILES, $numFiles);
    }

    private static function storeNativeLongProperty(
        Context $context,
        Value $obj,
        string $prop,
        Value $longVal
    ): void {
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            ZipArchiveJitSupport::CLASS_NAME,
            $prop,
            $longVal
        );
    }

    private static function storeValueStringProperty(
        Context $context,
        Value $obj,
        string $prop,
        Value $strPtr
    ): void {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $strPtr
        );
        $valVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            $ptr
        );
        $context->type->object->storeInstanceProperty(
            $obj,
            ZipArchiveJitSupport::CLASS_NAME,
            $prop,
            $valVar
        );
    }

    private static function loadFilenameString(Context $context, Value $obj): Value
    {
        $propVar = $context->type->object->propertyFetch(
            $obj,
            ZipArchiveJitSupport::CLASS_NAME,
            VmZipArchive::PROP_FILENAME
        );
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $propVar);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function storeHandle(Context $context, Value $obj, Value $handleI64): void
    {
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            ZipArchiveJitSupport::CLASS_NAME,
            ZipArchiveJitSupport::PROP_ID,
            $handleI64
        );
    }

    private static function loadHandle(Context $context, Value $obj): Value
    {
        $handleVar = $context->type->object->propertyFetch(
            $obj,
            ZipArchiveJitSupport::CLASS_NAME,
            ZipArchiveJitSupport::PROP_ID
        );

        return $context->helper->loadValue($handleVar);
    }

    private static function boxBoolFromI64(Context $context, Value $okI64): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $isTrue = $context->builder->icmp(Builder::INT_NE, $okI64, $i64->constInt(0, false));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $isTrue);

        return JitValueBox::pointer($context, $slot);
    }

    /**
     * Open php://memory, write NestedJIT payload, rewind, box stream handle (#35534).
     */
    private static function boxStreamFromPayload(Context $context, Value $foundI64, Value $payload): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $isFound = $context->builder->icmp(Builder::INT_NE, $foundI64, $i64->constInt(0, false));
        $id = (string) (++self::$serial);
        $okBlock = BasicBlockHelper::append($context, 'zip_gstr_box_ok_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_gstr_box_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_gstr_box_done_'.$id);
        $context->builder->branchIf($isFound, $okBlock, $missBlock);

        $context->builder->positionAtEnd($okBlock);
        $path = $context->builder->load($context->constantStringFromString('php://memory'));
        $mode = $context->builder->load($context->constantStringFromString('w+b'));
        $streamHandle = $context->builder->call(
            $context->lookupFunction('__compiler_fopen'),
            $path,
            $mode
        );
        $openFail = $context->builder->icmp(Builder::INT_SLT, $streamHandle, $i64->constInt(0, false));
        $openFailBlock = BasicBlockHelper::append($context, 'zip_gstr_fopen_fail_'.$id);
        $writeBlock = BasicBlockHelper::append($context, 'zip_gstr_fwrite_'.$id);
        $context->builder->branchIf($openFail, $openFailBlock, $writeBlock);

        $context->builder->positionAtEnd($openFailBlock);
        $ofSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $ofSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $ofPtr = JitValueBox::pointer($context, $ofSlot);
        $ofTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($writeBlock);
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $payload);
        $context->builder->call(
            $context->lookupFunction('__compiler_fwrite'),
            $streamHandle,
            $payload,
            $len
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_fseek'),
            $streamHandle,
            $i64->constInt(0, false),
            $i64->constInt(0, false)
        );
        $okSlot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $okSlot, $streamHandle);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $missSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $missSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $missPtr = JitValueBox::pointer($context, $missSlot);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $ptrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($ptrTy);
        $phi->addIncoming($okPtr, $okTail);
        $phi->addIncoming($ofPtr, $ofTail);
        $phi->addIncoming($missPtr, $missTail);

        return $phi;
    }

    /**
     * Materialize addGlob/addPattern path list from NestedJIT payload, or false (#35537).
     *
     * rc == -1 → false; else rc is count (0..2) and payload is len-prefixed paths.
     */
    private static function boxPathListOrFalse(Context $context, Value $rcI64, Value $payload): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $isFalse = $context->builder->icmp(
            Builder::INT_EQ,
            $rcI64,
            $i64->constInt(ZipArchiveJitHelper::ADDPATHS_FALSE_RC, true)
        );
        $id = (string) (++self::$serial);
        $falseBlock = BasicBlockHelper::append($context, 'zip_paths_false_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zip_paths_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_paths_done_'.$id);
        $context->builder->branchIf($isFalse, $falseBlock, $okBlock);

        $context->builder->positionAtEnd($falseBlock);
        $falseSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $falseSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        $falseTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $setStringAt = $context->lookupFunction('__hashtable__setStringAt');
        $sizeT = $context->getTypeFromString('size_t');
        // Unroll ≤2 paths (NestedJIT slot cap).
        $is1 = $context->builder->icmp(Builder::INT_SGE, $rcI64, $i64->constInt(1, false));
        $has1 = BasicBlockHelper::append($context, 'zip_paths_has1_'.$id);
        $after1 = BasicBlockHelper::append($context, 'zip_paths_after1_'.$id);
        $context->builder->branchIf($is1, $has1, $after1);

        $context->builder->positionAtEnd($has1);
        $len0 = self::int32LeAtStringOffset($context, $payload, 0);
        $str0 = self::stringFromPayloadLen($context, $payload, 4, $len0);
        $context->builder->call(
            $setStringAt,
            $ht,
            $context->builder->pointerCast($i64->constInt(0, false), $sizeT),
            $str0
        );
        $has1Tail = $context->builder->getInsertBlock();
        $context->builder->branch($after1);

        $context->builder->positionAtEnd($after1);
        $is2 = $context->builder->icmp(Builder::INT_SGE, $rcI64, $i64->constInt(2, false));
        $has2 = BasicBlockHelper::append($context, 'zip_paths_has2_'.$id);
        $after2 = BasicBlockHelper::append($context, 'zip_paths_after2_'.$id);
        $context->builder->branchIf($is2, $has2, $after2);

        $context->builder->positionAtEnd($has2);
        // Second path starts after len0(4) + path0 bytes — re-read len0.
        $len0b = self::int32LeAtStringOffset($context, $payload, 0);
        $off1 = $context->builder->add($len0b, $i64->constInt(4, false));
        // Need dynamic offset for len1 — use int32LeAtDynamicOffset.
        $len1 = self::int32LeAtDynamicOffset($context, $payload, $off1);
        $str1Off = $context->builder->add($off1, $i64->constInt(4, false));
        $str1 = self::stringFromPayloadDynamic($context, $payload, $str1Off, $len1);
        $context->builder->call(
            $setStringAt,
            $ht,
            $context->builder->pointerCast($i64->constInt(1, false), $sizeT),
            $str1
        );
        $has2Tail = $context->builder->getInsertBlock();
        $context->builder->branch($after2);

        $context->builder->positionAtEnd($after2);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $okPtr,
            $ht
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($falsePtr, $falseTail);
        $phi->addIncoming($okPtr, $okTail);

        return $phi;
    }

    /** Decode LE int32 at dynamic byte offset inside a __string__ payload into sext i64. */
    private static function int32LeAtDynamicOffset(Context $context, Value $strPtr, Value $byteOffset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $at = $context->builder->gep($data, $byteOffset);
        $b0 = $context->builder->load($context->builder->gep($at, $context->constantFromInteger(0, 'size_t')));
        $b1 = $context->builder->load($context->builder->gep($at, $context->constantFromInteger(1, 'size_t')));
        $b2 = $context->builder->load($context->builder->gep($at, $context->constantFromInteger(2, 'size_t')));
        $b3 = $context->builder->load($context->builder->gep($at, $context->constantFromInteger(3, 'size_t')));
        $u0 = $context->builder->zext($b0, $i32);
        $u1 = $context->builder->shl($context->builder->zext($b1, $i32), $i32->constInt(8, false));
        $u2 = $context->builder->shl($context->builder->zext($b2, $i32), $i32->constInt(16, false));
        $u3 = $context->builder->shl($context->builder->zext($b3, $i32), $i32->constInt(24, false));
        $packed = $context->builder->or($context->builder->or($u0, $u1), $context->builder->or($u2, $u3));

        return $context->builder->sext($packed, $i64);
    }

    /** Slice __string__ of known length from fixed byte offset. */
    private static function stringFromPayloadLen(
        Context $context,
        Value $strPtr,
        int $byteOffset,
        Value $lenI64
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $lenI64, $i64->constInt(0, false));
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_plen_empty_'.$id);
        $sliceBlock = BasicBlockHelper::append($context, 'zip_plen_slice_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_plen_done_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $sliceBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $emptyTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($sliceBlock);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $cstr = $context->builder->gep($data, $context->constantFromInteger($byteOffset, 'size_t'));
        $sliced = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $context->builder->pointerCast($cstr, $context->getTypeFromString('char*'))
        );
        $sliceTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strTy);
        $phi->addIncoming($empty, $emptyTail);
        $phi->addIncoming($sliced, $sliceTail);

        return $phi;
    }

    /** Slice __string__ of known length from dynamic byte offset. */
    private static function stringFromPayloadDynamic(
        Context $context,
        Value $strPtr,
        Value $byteOffset,
        Value $lenI64
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $lenI64, $i64->constInt(0, false));
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_pdyn_empty_'.$id);
        $sliceBlock = BasicBlockHelper::append($context, 'zip_pdyn_slice_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_pdyn_done_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $sliceBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $emptyTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($sliceBlock);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $cstr = $context->builder->gep($data, $byteOffset);
        $sliced = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $context->builder->pointerCast($cstr, $context->getTypeFromString('char*'))
        );
        $sliceTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strTy);
        $phi->addIncoming($empty, $emptyTail);
        $phi->addIncoming($sliced, $sliceTail);

        return $phi;
    }

    /**
     * Materialize RETURN_SB hashtable from NestedJIT packed payload, or false on miss (#35504).
     *
     * Payload layout: index,crc,size,mtime,comp_size,comp_method,encryption_method (7×int32) + name.
     */
    private static function boxStatOrFalse(Context $context, Value $foundI64, Value $payload): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $isFound = $context->builder->icmp(Builder::INT_NE, $foundI64, $i64->constInt(0, false));
        $id = (string) (++self::$serial);
        $okBlock = BasicBlockHelper::append($context, 'zip_stat_ok_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_stat_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_stat_done_'.$id);
        $context->builder->branchIf($isFound, $okBlock, $missBlock);

        $context->builder->positionAtEnd($okBlock);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $index = self::int32LeAtStringOffset($context, $payload, 0);
        $crc = self::int32LeAtStringOffset($context, $payload, 4);
        $size = self::int32LeAtStringOffset($context, $payload, 8);
        $mtime = self::int32LeAtStringOffset($context, $payload, 12);
        $compSize = self::int32LeAtStringOffset($context, $payload, 16);
        $compMethod = self::int32LeAtStringOffset($context, $payload, 20);
        $encMethod = self::int32LeAtStringOffset($context, $payload, 24);
        $name = self::stringFromPayloadOffset($context, $payload, ZipArchiveJitHelper::STAT_FIELD_BYTES);
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');
        $context->builder->call(
            $setString,
            $ht,
            $context->builder->load($context->constantStringFromString('name')),
            $name
        );
        foreach (
            [
                ['index', $index],
                ['crc', $crc],
                ['size', $size],
                ['mtime', $mtime],
                ['comp_size', $compSize],
                ['comp_method', $compMethod],
                ['encryption_method', $encMethod],
            ] as [$key, $val]
        ) {
            $context->builder->call(
                $setLong,
                $ht,
                $context->builder->load($context->constantStringFromString($key)),
                $val
            );
        }
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $okPtr,
            $ht
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $missSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $missSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $missPtr = JitValueBox::pointer($context, $missSlot);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($okPtr, $okTail);
        $phi->addIncoming($missPtr, $missTail);

        return $phi;
    }

    /** Decode LE int32 at byte offset inside a __string__ payload into sext i64. */
    private static function int32LeAtStringOffset(Context $context, Value $strPtr, int $byteOffset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $at = $context->builder->gep($data, $context->constantFromInteger($byteOffset, 'size_t'));
        $b0 = $context->builder->load($context->builder->gep($at, $context->constantFromInteger(0, 'size_t')));
        $b1 = $context->builder->load($context->builder->gep($at, $context->constantFromInteger(1, 'size_t')));
        $b2 = $context->builder->load($context->builder->gep($at, $context->constantFromInteger(2, 'size_t')));
        $b3 = $context->builder->load($context->builder->gep($at, $context->constantFromInteger(3, 'size_t')));
        $u0 = $context->builder->zext($b0, $i32);
        $u1 = $context->builder->shl($context->builder->zext($b1, $i32), $i32->constInt(8, false));
        $u2 = $context->builder->shl($context->builder->zext($b2, $i32), $i32->constInt(16, false));
        $u3 = $context->builder->shl($context->builder->zext($b3, $i32), $i32->constInt(24, false));
        $packed = $context->builder->or($context->builder->or($u0, $u1), $context->builder->or($u2, $u3));

        return $context->builder->zext($packed, $i64);
    }

    /** Slice __string__ from byte offset to end (entry name after RETURN_SB ints). */
    private static function stringFromPayloadOffset(Context $context, Value $strPtr, int $byteOffset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $off = $i64->constInt($byteOffset, false);
        $payLen = $context->builder->sub($len, $off);
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $payLen, $i64->constInt(0, false));
        $id = (string) (++self::$serial);
        $emptyBlock = BasicBlockHelper::append($context, 'zip_stat_name_empty_'.$id);
        $sliceBlock = BasicBlockHelper::append($context, 'zip_stat_name_slice_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_stat_name_done_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $sliceBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $emptyTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($sliceBlock);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $nameCstr = $context->builder->gep($data, $context->constantFromInteger($byteOffset, 'size_t'));
        $sliced = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $payLen,
            $context->builder->pointerCast($nameCstr, $context->getTypeFromString('char*'))
        );
        $sliceTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strTy);
        $phi->addIncoming($empty, $emptyTail);
        $phi->addIncoming($sliced, $sliceTail);

        return $phi;
    }

    /** locateName: index >= 0 → long; -1 miss → false (#35437). */
    private static function boxLongOrFalseFromI64(Context $context, Value $idxI64): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $isMiss = $context->builder->icmp(Builder::INT_SLT, $idxI64, $i64->constInt(0, false));
        $id = (string) (++self::$serial);
        $okBlock = BasicBlockHelper::append($context, 'zip_loc_ok_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'zip_loc_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zip_loc_done_'.$id);
        $context->builder->branchIf($isMiss, $missBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $okPtr,
            $idxI64
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missBlock);
        $missSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $missSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $missPtr = JitValueBox::pointer($context, $missSlot);
        $missTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($okPtr, $okTail);
        $phi->addIncoming($missPtr, $missTail);

        return $phi;
    }
}
