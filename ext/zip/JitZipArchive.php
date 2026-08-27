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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for ZipArchive open/add/close/get/locate/index/extract (#35424 / #35437 / #35440 / #35449 / #35465 / #35467 / #35473).
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
        $ok = self::execLong(
            $context,
            'rename',
            $handle,
            $context->getTypeFromString('int64')->constInt(0, false),
            $name,
            $newName
        );
        self::syncProps($context, $obj, $handle);

        return self::boxBoolFromI64($context, $ok);
    }

    /** ZipArchive::renameIndex — NestedJIT rename_index for slots 0/1 (#35473 leftover of #35450). */
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
        $empty = ZipArchiveEmbedBridge::emptyString($context);
        $ok = self::execLong(
            $context,
            'rename_index',
            $index,
            $i64->constInt(0, false),
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
