<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin libc stat(2)/lstat(2)/access(2) for path predicates + long fields (#19215, #27013).
 *
 * Standalone mode helpers avoid LLVM miscompile when nested into helper TUs (#8555).
 * Keep glibc layout here — not in {@see JitStat} (#9112 shrink).
 * Long fields: NestedJIT VmStatCache arrays mis-read under thin AOT (#27013); peer
 * {@see \PHPCompiler\JIT\Builtin\FtokRuntime} emits the platform leaf in LLVM.
 * php-src: ext/standard/filestat.c
 */
final class JitStatKernel
{
    /** sizeof(struct stat) on Linux x86_64 glibc */
    private const STAT_BUF_SIZE = 144;

    /** offsetof(struct stat, st_mode) on Linux x86_64 glibc */
    private const STAT_MODE_OFFSET = 24;

    /** @var array<int, array{offset: int, width: int}> fieldId → {byte offset, load width 4|8} */
    private const LONG_FIELD_LAYOUT = [
        // StatFieldsJitHelper::FIELD_* — glibc x86_64 struct stat
        StatFieldsJitHelper::FIELD_SIZE => ['offset' => 48, 'width' => 8],   // st_size
        StatFieldsJitHelper::FIELD_MTIME => ['offset' => 88, 'width' => 8],  // st_mtim.tv_sec
        StatFieldsJitHelper::FIELD_ATIME => ['offset' => 72, 'width' => 8],  // st_atim.tv_sec
        StatFieldsJitHelper::FIELD_CTIME => ['offset' => 104, 'width' => 8], // st_ctim.tv_sec
        StatFieldsJitHelper::FIELD_INO => ['offset' => 8, 'width' => 8],     // st_ino
        StatFieldsJitHelper::FIELD_UID => ['offset' => 28, 'width' => 4],    // st_uid
        StatFieldsJitHelper::FIELD_GID => ['offset' => 32, 'width' => 4],    // st_gid
        StatFieldsJitHelper::FIELD_DEV => ['offset' => 0, 'width' => 8],     // st_dev
        StatFieldsJitHelper::FIELD_MODE => ['offset' => 24, 'width' => 4],   // st_mode
    ];

    /** @return Value i32 — st_mode, or -1 on failure */
    public static function mode(Context $context, Value $pathStr, bool $useLstat): Value
    {
        $statFn = $useLstat ? 'lstat' : 'stat';
        $fn = self::ensureModeStandalone($context, $statFn);

        return $context->builder->call($fn, $pathStr);
    }

    /**
     * Read a php-stat long field via libc (i64, or -1 on failure).
     *
     * @param Value $useLstat i64 — 0 = stat(2), nonzero = lstat(2)
     * @param Value $fieldId  i64 — {@see StatFieldsJitHelper} FIELD_* constant
     */
    public static function longField(Context $context, Value $pathStr, Value $useLstat, Value $fieldId): Value
    {
        $fn = self::ensureLongFieldStandalone($context);

        return $context->builder->call($fn, $pathStr, $useLstat, $fieldId);
    }

    /** @return Value i1 — access(2) succeeds */
    public static function accessOk(Context $context, Value $pathStr, int $mode): Value
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('access'),
            $pathPtr,
            $i32->constInt($mode, false)
        );

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(0, false));
    }

    private static function ensureModeStandalone(Context $context, string $statFn): Value
    {
        $name = '__phpc_jit_stat_mode_kernel_'.$statFn;
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction($name, $existing);

            return $existing;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $strPtr)
        );
        $entry = $fn->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($entry);

        $str = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($str, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $bufType = $i8->arrayType(self::STAT_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'phpc_stat_mode_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $ret = $context->builder->call(
            $context->lookupFunction($statFn),
            $pathPtr,
            $bufPtr
        );
        $zero = $i32->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_NE, $ret, $zero);
        $bytePtr = $context->builder->gep($bufPtr, $i64->constInt(self::STAT_MODE_OFFSET, false));
        $modePtr = $context->builder->pointerCast($bytePtr, $i32->pointerType(0));
        $mode = $context->builder->load($modePtr);
        $minusOne = $i32->constInt(-1, true);
        $context->builder->returnValue($context->builder->select($failed, $minusOne, $mode));
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLongFieldStandalone(Context $context): Value
    {
        $name = '__phpc_jit_stat_long_field_kernel';
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction($name, $existing);

            return $existing;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($i64, false, $strPtr, $i64, $i64)
        );
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();

        $entry = $fn->appendBasicBlock('entry');
        $fail = $fn->appendBasicBlock('fail');
        $statOk = $fn->appendBasicBlock('stat_ok');
        $useLstatBlock = $fn->appendBasicBlock('use_lstat');
        $useStatBlock = $fn->appendBasicBlock('use_stat');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $useLstat = $fn->getParam(1);
        $fieldId = $fn->getParam(2);
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($path, $map['value']);
        $bufType = $i8->arrayType(self::STAT_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'phpc_stat_long_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $wantLstat = $context->builder->icmp(Builder::INT_NE, $useLstat, $i64->constInt(0, false));
        $context->builder->branchIf($wantLstat, $useLstatBlock, $useStatBlock);

        $context->builder->positionAtEnd($useStatBlock);
        $statRet = $context->builder->call($context->lookupFunction('stat'), $pathPtr, $bufPtr);
        $statEnd = $context->builder->getInsertBlock();
        $context->builder->branch($statOk);

        $context->builder->positionAtEnd($useLstatBlock);
        $lstatRet = $context->builder->call($context->lookupFunction('lstat'), $pathPtr, $bufPtr);
        $lstatEnd = $context->builder->getInsertBlock();
        $context->builder->branch($statOk);

        $context->builder->positionAtEnd($statOk);
        $rc = $context->builder->phi($i32);
        $rc->addIncoming($statRet, $statEnd);
        $rc->addIncoming($lstatRet, $lstatEnd);
        $failed = $context->builder->icmp(Builder::INT_NE, $rc, $i32->constInt(0, false));
        $dispatch = $fn->appendBasicBlock('dispatch');
        $context->builder->branchIf($failed, $fail, $dispatch);

        $context->builder->positionAtEnd($dispatch);
        // Chain of field selects — fieldId is almost always a compile-time constant at call sites.
        $next = $dispatch;
        foreach (self::LONG_FIELD_LAYOUT as $id => $layout) {
            $matchBlock = $fn->appendBasicBlock('field_'.$id);
            $contBlock = $fn->appendBasicBlock('field_cont_'.$id);
            $context->builder->positionAtEnd($next);
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $fieldId, $i64->constInt($id, false));
            $context->builder->branchIf($isMatch, $matchBlock, $contBlock);

            $context->builder->positionAtEnd($matchBlock);
            $bytePtr = $context->builder->gep($bufPtr, $i64->constInt($layout['offset'], false));
            if (8 === $layout['width']) {
                $valPtr = $context->builder->pointerCast($bytePtr, $i64->pointerType(0));
                $loaded = $context->builder->load($valPtr);
            } else {
                $valPtr = $context->builder->pointerCast($bytePtr, $i32->pointerType(0));
                $loaded = $context->builder->zext($context->builder->load($valPtr), $i64);
            }
            $context->builder->returnValue($loaded);
            $next = $contBlock;
        }
        $context->builder->positionAtEnd($next);
        $context->builder->returnValue($i64->constInt(-1, true));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction($name, $fn);

        return $fn;
    }
}
