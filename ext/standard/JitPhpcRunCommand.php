<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for phpc_run_command() via __compiler_phpc_run_command (#2779). */
final class JitPhpcRunCommand
{
    /** @return Value */
    public static function invoke(Context $context, Value $cmdStr, ?JITVariable $envArg): Value
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $envHt = $htPtrTy->constNull();
        if (null !== $envArg) {
            $envHt = self::loadArrayArg($context, $envArg, 2);
        }
        $resultHt = $context->builder->call(
            $context->lookupFunction('__compiler_phpc_run_command'),
            $cmdStr,
            $envHt
        );
        $failed = $context->builder->icmp(Builder::INT_EQ, $resultHt, $htPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'phpc_run_command_fail');
        $okBlock = BasicBlockHelper::append($context, 'phpc_run_command_ok');
        $doneBlock = BasicBlockHelper::append($context, 'phpc_run_command_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $resultHt);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function loadArrayArg(Context $context, JITVariable $arg, int $position): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::pointer($context, $arg->value)
            );
        }

        throw new \LogicException(
            "phpc_run_command() argument #{$position} must be an array in this compiler build"
        );
    }
}
