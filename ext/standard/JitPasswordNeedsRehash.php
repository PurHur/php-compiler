<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringPasswordCrypto;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for password_needs_rehash() (issue #3279). */
final class JitPasswordNeedsRehash
{
    public static function invoke(
        Context $context,
        Value $hash,
        JITVariable $algo,
        ?JITVariable $options
    ): Value {
        StringPasswordCrypto::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $algoI64 = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $algo, 'password_needs_rehash() algorithm'),
            $i64
        );
        $newCost = self::lowerBcryptCostOption($context, $options);

        $needs = $context->builder->call(
            $context->lookupFunction('__compiler_password_needs_rehash'),
            $hash,
            $algoI64,
            $newCost
        );

        return $context->builder->icmp(
            Builder::INT_NE,
            $needs,
            $i32->constInt(0, false)
        );
    }

    private static function lowerBcryptCostOption(Context $context, ?JITVariable $options): Value
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
                'password_needs_rehash() options must be an array in this compiler build'
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
        $tag = 'pnr_cost_'.bin2hex(random_bytes(4));
        $hasBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, $tag.'_has');
        $missBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, $tag.'_miss');
        $doneBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, $tag.'_done');
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
