<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ProcessOpen;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for proc_open() via __compiler_proc_open (#6904). */
final class JitProcOpen
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        // STANDALONE/EMBED AOT skips eager ProcessOpen link (#12910); ensure before lookup (#23722).
        ProcessOpen::ensureLinked($context);
        if (\count($args) < 3) {
            throw new \LogicException('proc_open() requires at least three arguments in this compiler build');
        }

        $commandStr = JitStringArg::lower($context, $args[0], 'proc_open() command');
        $pipesHt = HashTableHelper::ensureHashtablePointer($context, $args[2]);

        $handle = $context->builder->call(
            $context->lookupFunction('__compiler_proc_open'),
            $commandStr,
            $pipesHt
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_SLT, $handle, $i64->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'proc_open_fail');
        $okBlock = BasicBlockHelper::append($context, 'proc_open_ok');
        $doneBlock = BasicBlockHelper::append($context, 'proc_open_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $handle);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
