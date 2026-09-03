<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static bridge for Dom VM runtime hooks owned by ext/dom (#36204).
 *
 * lib/ must not import PHPCompiler\ext\dom; Module::init registers callables.
 */
final class DomVmRuntimeSupport
{
    /** @var null|callable(Variable): void */
    private static $retainUserHandleFromVariable = null;

    /** @var null|callable(ObjectEntry): ?string */
    private static $fetchableNodeErrorMessage = null;

    public static function clear(): void
    {
        self::$retainUserHandleFromVariable = null;
        self::$fetchableNodeErrorMessage = null;
    }

    /** @param callable(Variable): void $hook */
    public static function setRetainUserHandleFromVariable(callable $hook): void
    {
        self::$retainUserHandleFromVariable = $hook;
    }

    /** @param callable(ObjectEntry): ?string $hook */
    public static function setFetchableNodeErrorMessage(callable $hook): void
    {
        self::$fetchableNodeErrorMessage = $hook;
    }

    public static function retainUserHandleFromVariable(Variable $var): void
    {
        if (null !== self::$retainUserHandleFromVariable) {
            (self::$retainUserHandleFromVariable)($var);
        }
    }

    public static function fetchableNodeErrorMessage(ObjectEntry $node): ?string
    {
        if (null === self::$fetchableNodeErrorMessage) {
            return null;
        }

        return (self::$fetchableNodeErrorMessage)($node);
    }
}
