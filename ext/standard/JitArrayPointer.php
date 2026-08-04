<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ArrayPointerRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for array internal pointer builtins (ext/standard/array.c; #4967, #5504, #27484).
 *
 * Call-site via {@see ArrayPointerRuntime} → {@see \PHPCompiler\JIT\HashTablePointerLlvm}.
 * php-src: {@see https://github.com/php/php-src/blob/master/ext/standard/array.c}
 */
final class JitArrayPointer
{
    public static function key(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'key');

        return ArrayPointerRuntime::key($context, $array);
    }

    public static function current(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'current');

        return ArrayPointerRuntime::current($context, $array);
    }

    public static function next(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'next');

        return ArrayPointerRuntime::next($context, $array);
    }

    public static function prev(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'prev');

        return ArrayPointerRuntime::prev($context, $array);
    }

    public static function reset(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'reset');

        return ArrayPointerRuntime::reset($context, $array);
    }

    public static function end(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'end');

        return ArrayPointerRuntime::end($context, $array);
    }

    private static function requireArrayArg(Context $context, JITVariable $array, string $fn): void
    {
        JitArrayKey::requireArrayArg($context, $array, $fn);
    }
}
