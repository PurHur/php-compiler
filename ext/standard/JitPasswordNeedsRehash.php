<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringPasswordCrypto;
use PHPCompiler\JIT\Context;
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
        $algoI64 = JitPasswordAlgo::lower(
            $context,
            $algo,
            'password_needs_rehash',
            1,
            'algo'
        );
        $newCost = JitPasswordBcryptCost::lowerFromOptions($context, $options, 'password_needs_rehash');

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
}
