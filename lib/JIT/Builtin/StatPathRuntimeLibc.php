<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;

/**
 * Thin libc stat(2) bridges for user-script standalone AOT (#16734).
 *
 * Nested StatPathJitHelper segfaults in minimal standalone init.
 * php-src: ext/standard/filestat.c
 */
final class StatPathRuntimeLibc
{
    /** sizeof(struct stat) on Linux x86_64 glibc */
    private const STAT_BUF_SIZE = 144;

    /** offsetof(struct stat, st_mode) on Linux x86_64 glibc */
    private const STAT_MODE_OFFSET = 24;

    private const S_IFMT = 0xF000;

    private const S_IFREG = 0x8000;

    public static function ensureLinked(Context $context): void
    {
        self::implementIsFile($context);
    }

    private static function implementIsFile(Context $context): void
    {
        $abiName = StatPathRuntime::FN_IS_FILE;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i1, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('is_file_libc_entry');
        $fail = $fn->appendBasicBlock('is_file_libc_null');
        $run = $fn->appendBasicBlock('is_file_libc_run');
        $statFail = $fn->appendBasicBlock('is_file_libc_stat_fail');
        $statOk = $fn->appendBasicBlock('is_file_libc_stat_ok');
        $done = $fn->appendBasicBlock('is_file_libc_done');

        $context->builder->positionAtEnd($entry);
        $path = $fn->getParam(0);
        $nullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($nullPath, $fail, $run);

        $context->builder->positionAtEnd($fail);
        $falseVal = $i1->constInt(0, false);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($run);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($path, $map['value']);
        $bufType = $i8->arrayType(self::STAT_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'is_file_stat_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $ret = $context->builder->call(
            $context->lookupFunction('stat'),
            $pathPtr,
            $bufPtr
        );
        $statFailed = $context->builder->icmp(Builder::INT_SLT, $ret, $i32->constInt(0, true));
        $context->builder->branchIf($statFailed, $statFail, $statOk);

        $context->builder->positionAtEnd($statFail);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($statOk);
        $i64 = $context->getTypeFromString('int64');
        $modePtr = $context->builder->pointerCast(
            $context->builder->gep($bufPtr, $i64->constInt(self::STAT_MODE_OFFSET, false)),
            $i32->pointerType(0)
        );
        $mode = $context->builder->load($modePtr);
        $masked = $context->builder->and(
            $mode,
            $i32->constInt(self::S_IFMT, false)
        );
        $matches = $context->builder->icmp(
            Builder::INT_EQ,
            $masked,
            $i32->constInt(self::S_IFREG, false)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $fail);
        $phi->addIncoming($falseVal, $statFail);
        $phi->addIncoming($matches, $statOk);
        $context->builder->returnValue($phi);

        $context->registerFunction($abiName, $fn);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
