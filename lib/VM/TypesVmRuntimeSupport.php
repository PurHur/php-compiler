<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context as JitContext;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPLLVM\Value;

/**
 * Static bridge for ext/types JIT Call surfaces owned by the types Module (#36204).
 *
 * lib/JIT/Builtin must not import PHPCompiler\ext\types; Module::init registers.
 *
 * php-src: ext/standard/type.c — PHP_FUNCTION(is_null) / is_* family.
 */
final class TypesVmRuntimeSupport
{
    /** @var null|Call */
    private static $isNullCall = null;

    public static function clear(): void
    {
        self::$isNullCall = null;
    }

    public static function setIsNullCall(Call $call): void
    {
        self::$isNullCall = $call;
    }

    public static function isNullCall(): ?Call
    {
        return self::$isNullCall;
    }

    /**
     * @param list<JitVariable> $args
     */
    public static function callIsNull(JitContext $context, JitVariable ...$args): Value
    {
        if (null === self::$isNullCall) {
            throw new \LogicException(
                'TypesVmRuntimeSupport is_null Call not registered — load ext/types Module (#36204)'
            );
        }

        return self::$isNullCall->call($context, ...$args);
    }
}
