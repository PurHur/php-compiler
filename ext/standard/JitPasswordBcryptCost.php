<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Shared JIT lowering for password_*() bcrypt `cost` option (#4741, #3279). */
final class JitPasswordBcryptCost
{
    /**
     * Lower options array to bcrypt cost i64 (0 = use default in LLVM helper).
     */
    public static function lowerFromOptions(Context $context, ?JITVariable $options, string $function): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $defaultCost = $i64->constInt(0, false);

        if (null === $options) {
            return $defaultCost;
        }

        $key = $context->builder->load($context->constantStringFromString('cost'));
        $ht = null;
        if (JITVariable::TYPE_HASHTABLE === $options->type) {
            $ht = $context->helper->loadValue($options);
        } elseif (JITVariable::TYPE_VALUE === $options->type) {
            $ht = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $context->helper->loadValue($options)
            );
        } else {
            throw new \LogicException(
                \sprintf('%s() options must be an array in this compiler build', $function)
            );
        }

        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $key
        );
        $hasCost = $context->builder->icmp(
            Builder::INT_NE,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $tag = 'pw_cost_'.bin2hex(random_bytes(4));
        $hasBlock = BasicBlockHelper::append($context, $tag.'_has');
        $missBlock = BasicBlockHelper::append($context, $tag.'_miss');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($hasCost, $hasBlock, $missBlock);

        $context->builder->positionAtEnd($missBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($hasBlock);
        $costLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valPtr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($defaultCost, $missBlock);
        $phi->addIncoming($costLong, $hasBlock);

        return $phi;
    }
}
