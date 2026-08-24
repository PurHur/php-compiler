<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFilePutContents;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for file_put_contents() via {@see \PHPCompiler\JIT\Builtin\StringFilePutContents}.
 *
 * NestedJIT leaf: {@see JitFilePutContentsLibc} so `@file_put_contents` does not re-enter
 * {@see FilePutContentsJitHelper} via `__compiler_file_put_contents` (#30127 / #29833).
 * Call-site {@see StringFilePutContents::ensureLinked} before lookup (#34423) — Type no longer
 * eagerly links on initialize (peer #34414).
 */
final class JitFilePutContents
{
    private static function ensureCompilerAbi(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        $savedInsert = null;
        try {
            $savedInsert = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        StringFilePutContents::ensureLinked($context);
        if (null !== $savedInsert) {
            $context->builder->positionAtEnd($savedInsert);
        }
    }

    /** @return Value */
    public static function invoke(Context $context, Value $pathStr, Value $dataStr, Value $flagsLong): Value
    {
        if (NestedJitCompileScope::isActive()) {
            $bytes = JitFilePutContentsLibc::call($context, $pathStr, $dataStr, $flagsLong);
        } else {
            self::ensureCompilerAbi($context);
            $bytes = $context->builder->call(
                $context->lookupFunction('__compiler_file_put_contents'),
                $pathStr,
                $dataStr,
                $flagsLong
            );
        }
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_SLT, $bytes, $zero);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'fpc_fail');
        $okBlock = BasicBlockHelper::append($context, 'fpc_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fpc_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $bytes);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
