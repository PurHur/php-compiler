<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for remaining phpc_fs_dir.c runtime symbols (#6982).
 */
final class StringFsDirJit
{
    private const PATH_MAX = 4096;

    private const STAT_BUF_SIZE = 144;
    private const STAT_MODE_OFFSET = 24;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_copy',
        '__compiler_resolve_sidecar_source_path',
        '__compiler_touch',
        '__compiler_mkdir',
        '__phpc_stat',
        '__compiler_sys_get_temp_dir',
        '__compiler_tempnam',
        '__compiler_chgrp',
        '__compiler_chown',
        '__compiler_ftok',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_copy');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibc($context);

        self::implementIfMissing($context, '__compiler_copy', self::emitCopy(...));
        self::implementIfMissing($context, '__compiler_resolve_sidecar_source_path', self::emitResolveSidecarSourcePath(...));
        FsDirRuntime::ensureLinked($context);
        SysGetTempDirRuntime::ensureLinked($context);
        StatArrayRuntime::ensureLinked($context);
        FtokRuntime::ensureLinked($context);
        ChownRuntime::ensureLinked($context);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $fn = match ($name) {
            '__compiler_copy' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $strPtr)
            ),
            '__compiler_resolve_sidecar_source_path' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr)
            ),
            '__compiler_touch' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $i64, $i64)
            ),
            '__compiler_mkdir' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $i64, $i32)
            ),
            '__phpc_stat' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $strPtr, $i32)
            ),
            '__compiler_sys_get_temp_dir' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false)
            ),
            '__compiler_tempnam' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr)
            ),
            '__compiler_ftok' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $i32)
            ),
            default => throw new \LogicException('Unknown fs dir JIT function: '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
        );
    }

    private static function stackBytesPtr(Context $context, Value $slot): Value
    {
        return $context->builder->pointerCast($slot, $context->getTypeFromString('int8*'));
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $litGlobal = $context->constantStringFromString($text);
        $litPtr = $context->builder->load($litGlobal);
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($litPtr, $map['value']);
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
    }

    private static function statFieldI32ToI64(Context $context, Value $statBase, int $offset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $at = $context->builder->gep($statBase, $i64->constInt($offset, false));
        $v = $context->builder->load($context->builder->pointerCast($at, $i32->pointerType(0)));

        return $context->builder->zExt($v, $i64);
    }

    private static function emitResolveSidecarSourcePath(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        $path = $fn->getParam(0);
        $src = self::stringData($context, $path);
        $exists = $context->builder->call($context->lookupFunction('access'), $src, $i32->constInt(0, false));
        $existsOk = $context->builder->icmp(Builder::INT_EQ, $exists, $i32->constInt(0, false));
        $returnOriginal = $fn->appendBasicBlock('resolve_return_original');
        $tryRemap = $fn->appendBasicBlock('resolve_try_remap');
        $context->builder->branchIf($existsOk, $returnOriginal, $tryRemap);

        $context->builder->positionAtEnd($tryRemap);
        $repoKey = $context->builder->pointerCast(
            $context->constantFromString('PHP_COMPILER_REPO_ROOT'),
            $i8p
        );
        $repo = $context->builder->call($context->lookupFunction('getenv'), $repoKey);
        $repoNull = $context->builder->icmp(Builder::INT_EQ, $repo, $i8p->constNull());
        self::ensureExternal($context, 'strstr', $context->context->functionType($i8p, false, $i8p, $i8p));
        $buildMarker = self::literalCstr($context, '/build/');
        $suffix = $context->builder->call($context->lookupFunction('strstr'), $src, $buildMarker);
        $suffixNull = $context->builder->icmp(Builder::INT_EQ, $suffix, $i8p->constNull());
        $canRemap = $context->builder->and(
            $context->builder->not($repoNull),
            $context->builder->not($suffixNull)
        );
        $returnOriginalFromRemap = $fn->appendBasicBlock('resolve_return_original_from_remap');
        $remap = $fn->appendBasicBlock('resolve_remap');
        $context->builder->branchIf($canRemap, $remap, $returnOriginalFromRemap);

        $context->builder->positionAtEnd($returnOriginalFromRemap);
        $context->builder->branch($returnOriginal);

        $context->builder->positionAtEnd($remap);
        $bufSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::PATH_MAX));
        $buf = self::stackBytesPtr($context, $bufSlot);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $buf,
            $sizeT->constInt(self::PATH_MAX, false),
            self::literalCstr($context, '%s%s'),
            $repo,
            $suffix
        );
        $remappedOk = $context->builder->call($context->lookupFunction('access'), $buf, $i32->constInt(0, false));
        $remappedExists = $context->builder->icmp(Builder::INT_EQ, $remappedOk, $i32->constInt(0, false));
        $returnOriginalAfterRemap = $fn->appendBasicBlock('resolve_return_original_after_remap');
        $returnRemapped = $fn->appendBasicBlock('resolve_return_remapped');
        $context->builder->branchIf($remappedExists, $returnRemapped, $returnOriginalAfterRemap);

        $context->builder->positionAtEnd($returnOriginalAfterRemap);
        $context->builder->branch($returnOriginal);

        $context->builder->positionAtEnd($returnRemapped);
        $remappedStr = self::cstrToString($context, $buf);
        $context->builder->returnValue($remappedStr);

        $context->builder->positionAtEnd($returnOriginal);
        $context->builder->returnValue($path);
    }

    private static function emitCopy(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        $from = $fn->getParam(0);
        $to = $fn->getParam(1);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullStr = $strPtr->constNull();
        $nullFile = $i8p->constNull();

        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $from, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $to, $nullStr)
        );
        $fail = $fn->appendBasicBlock('copy_fail');
        $openIn = $fn->appendBasicBlock('copy_open_in');
        $context->builder->branchIf($badArgs, $fail, $openIn);

        $context->builder->positionAtEnd($openIn);
        $src = self::stringData($context, $from);
        $dst = self::stringData($context, $to);
        $in = $context->builder->call($context->lookupFunction('fopen'), $src, self::literalCstr($context, 'rb'));
        $inNull = $context->builder->icmp(Builder::INT_EQ, $in, $nullFile);
        $openOut = $fn->appendBasicBlock('copy_open_out');
        $context->builder->branchIf($inNull, $fail, $openOut);

        $context->builder->positionAtEnd($openOut);
        $out = $context->builder->call($context->lookupFunction('fopen'), $dst, self::literalCstr($context, 'wb'));
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullFile);
        $closeInFail = $fn->appendBasicBlock('copy_close_in_fail');
        $prep = $fn->appendBasicBlock('copy_prep');
        $context->builder->branchIf($outNull, $closeInFail, $prep);

        $context->builder->positionAtEnd($closeInFail);
        $context->builder->call($context->lookupFunction('fclose'), $in);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($prep);
        $okSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($one, $okSlot);
        $bufSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(8192));
        $buf = self::stackBytesPtr($context, $bufSlot);
        $loop = $fn->appendBasicBlock('copy_loop');
        $write = $fn->appendBasicBlock('copy_write');
        $afterRead = $fn->appendBasicBlock('copy_after_read');
        $writeFail = $fn->appendBasicBlock('copy_write_fail');
        $shortTail = $fn->appendBasicBlock('copy_short_tail');
        $setReadErr = $fn->appendBasicBlock('copy_set_read_err');
        $afterLoop = $fn->appendBasicBlock('copy_after_loop');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $n = $context->builder->call(
            $context->lookupFunction('fread'),
            $buf,
            $sizeT->constInt(1, false),
            $sizeT->constInt(8192, false),
            $in
        );
        $hasBytes = $context->builder->icmp(Builder::INT_UGT, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($hasBytes, $write, $afterRead);

        $context->builder->positionAtEnd($write);
        $written = $context->builder->call(
            $context->lookupFunction('fwrite'),
            $buf,
            $sizeT->constInt(1, false),
            $n,
            $out
        );
        $writeBad = $context->builder->icmp(Builder::INT_NE, $written, $n);
        $context->builder->branchIf($writeBad, $writeFail, $afterRead);

        $context->builder->positionAtEnd($writeFail);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterLoop);

        $context->builder->positionAtEnd($afterRead);
        $shortRead = $context->builder->icmp(Builder::INT_ULT, $n, $sizeT->constInt(8192, false));
        $context->builder->branchIf($shortRead, $shortTail, $loop);

        $context->builder->positionAtEnd($shortTail);
        $inErr = $context->builder->call($context->lookupFunction('ferror'), $in);
        $hasErr = $context->builder->icmp(Builder::INT_NE, $inErr, $zero);
        $context->builder->branchIf($hasErr, $setReadErr, $afterLoop);

        $context->builder->positionAtEnd($setReadErr);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterLoop);

        $context->builder->positionAtEnd($afterLoop);
        $closeOut = $context->builder->call($context->lookupFunction('fclose'), $out);
        $outBad = $context->builder->icmp(Builder::INT_NE, $closeOut, $zero);
        $afterCloseOut = $fn->appendBasicBlock('copy_after_close_out');
        $closeOutErr = $fn->appendBasicBlock('copy_close_out_err');
        $context->builder->branchIf($outBad, $closeOutErr, $afterCloseOut);
        $context->builder->positionAtEnd($closeOutErr);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterCloseOut);

        $context->builder->positionAtEnd($afterCloseOut);
        $closeIn = $context->builder->call($context->lookupFunction('fclose'), $in);
        $inBad = $context->builder->icmp(Builder::INT_NE, $closeIn, $zero);
        $afterCloseIn = $fn->appendBasicBlock('copy_after_close_in');
        $closeInErr = $fn->appendBasicBlock('copy_close_in_err');
        $context->builder->branchIf($inBad, $closeInErr, $afterCloseIn);
        $context->builder->positionAtEnd($closeInErr);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterCloseIn);

        $context->builder->positionAtEnd($afterCloseIn);
        $ok = $context->builder->load($okSlot);
        $okBool = $context->builder->icmp(Builder::INT_EQ, $ok, $one);
        $chmodBlock = $fn->appendBasicBlock('copy_chmod');
        $retBlock = $fn->appendBasicBlock('copy_ret');
        $context->builder->branchIf($okBool, $chmodBlock, $retBlock);

        $context->builder->positionAtEnd($chmodBlock);
        $stSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::STAT_BUF_SIZE));
        $stBase = self::stackBytesPtr($context, $stSlot);
        $stRc = $context->builder->call($context->lookupFunction('stat'), $src, $stBase);
        $stOk = $context->builder->icmp(Builder::INT_EQ, $stRc, $zero);
        $chmodTail = $fn->appendBasicBlock('copy_chmod_tail');
        $chmodDo = $fn->appendBasicBlock('copy_chmod_do');
        $context->builder->branchIf($stOk, $chmodDo, $chmodTail);
        $context->builder->positionAtEnd($chmodDo);
        $mode64 = self::statFieldI32ToI64($context, $stBase, self::STAT_MODE_OFFSET);
        $context->builder->call($context->lookupFunction('chmod'), $dst, $context->builder->truncOrBitCast($mode64, $i32));
        $context->builder->branch($chmodTail);
        $context->builder->positionAtEnd($chmodTail);
        $context->builder->branch($retBlock);

        $context->builder->positionAtEnd($retBlock);
        $context->builder->returnValue($context->builder->load($okSlot));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFsDirJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
