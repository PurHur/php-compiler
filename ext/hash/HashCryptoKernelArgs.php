<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Shared arg lowering for phpc_hash_crypto_* NestedJIT leaves (#21026). */
final class HashCryptoKernelArgs
{
    public static function lowerBoolI32(Context $context, JITVariable $arg): Value
    {
        $i1 = JitBoolArg::lower($context, $arg, 'phpc_hash_crypto raw');

        return $context->builder->zExt($i1, $context->getTypeFromString('int32'));
    }

    public static function lowerInt64(Context $context, JITVariable $arg): Value
    {
        return JitLongArg::lower($context, $arg, 'phpc_hash_crypto int');
    }
}
