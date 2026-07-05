<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Lower stream_supports() $feature — int legacy codes or PHP 8.4 string names (#16329). */
final class JitStreamSupportsFeatureArg
{
    public static function lower(Context $context, JITVariable $arg): Value
    {
        if (null !== $arg->compileTimeString) {
            return self::constFeature($context, $arg->compileTimeString);
        }

        $literal = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            return self::constFeature($context, $literal);
        }

        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return JitLongArg::lower($context, $arg, 'stream_supports() feature');
    }

    private static function constFeature(Context $context, string $feature): Value
    {
        $resolved = VmStreamSupports::resolveFeatureFromString($feature) ?? -1;

        return $context->getTypeFromString('int64')->constInt($resolved, false);
    }
}
