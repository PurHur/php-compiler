<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ext\standard\JitFileGetContents;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
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
 * (#35424 / #35437 / #35440 / #35449 / #35450 / #35465 / #35467 / #35472 / #35476 / #35486).
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
        $ok = self::execLong(
            $context,
            "add",
            $handle,
            $context->getTypeFromString('int64')->constInt(0, false),
            $name,
            $content
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
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $rcOk = self::execLong(
            $context,
            'addir',
            $handle,
            $zero,
            $dirnameSlash,
            $empty
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
        $rcOk = self::execLong($context, 'add', $handle, $zero, $name, $content);
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
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        $ok = self::execLong(
            $context,
            'sac',
            $handle,
            $i64->constInt(0, false),
            $comment,
            $empty
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
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $i64 = $context->getTypeFromString('int64');
        [$found, $data] = self::execLongAndPayload(
            $context,
            'gac',
            $handle,
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
        $rcOk = self::execLong($context, 'rpl', $index, $zero, $empty, $content);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $rcPhi = $context->builder->phi($i64);
        $rcPhi->addIncoming($rcOk, $okTail);
        $rcPhi->addIncoming($rcMiss, $missTail);
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $rcPhi);
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
     * ZipArchive::setPassword — NestedJIT spw (#35500 leftover of #35496 / #19873).
     *
     * php-src: ext/zip/php_zip.c — zim_ZipArchive_setPassword
     * Empty password → false (not ValueError).
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
