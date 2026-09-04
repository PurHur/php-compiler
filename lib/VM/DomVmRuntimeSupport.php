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

    /** @var null|callable(ObjectEntry): bool */
    private static $isCollection = null;

    /** @var null|callable(ObjectEntry): bool */
    private static $isTokenList = null;

    /** @var null|callable(ObjectEntry, Variable): bool */
    private static $tokenListDimensionIsEmpty = null;

    /** @var null|callable(ObjectEntry, Variable): bool */
    private static $hasDimension = null;

    public static function clear(): void
    {
        self::$retainUserHandleFromVariable = null;
        self::$fetchableNodeErrorMessage = null;
        self::$isCollection = null;
        self::$isTokenList = null;
        self::$tokenListDimensionIsEmpty = null;
        self::$hasDimension = null;
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

    /** @param callable(ObjectEntry): bool $hook */
    public static function setIsCollection(callable $hook): void
    {
        self::$isCollection = $hook;
    }

    /** @param callable(ObjectEntry): bool $hook */
    public static function setIsTokenList(callable $hook): void
    {
        self::$isTokenList = $hook;
    }

    /** @param callable(ObjectEntry, Variable): bool $hook */
    public static function setTokenListDimensionIsEmpty(callable $hook): void
    {
        self::$tokenListDimensionIsEmpty = $hook;
    }

    /** @param callable(ObjectEntry, Variable): bool $hook */
    public static function setHasDimension(callable $hook): void
    {
        self::$hasDimension = $hook;
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

    public static function isCollection(ObjectEntry $object): bool
    {
        return null !== self::$isCollection && (self::$isCollection)($object);
    }

    public static function isTokenList(ObjectEntry $object): bool
    {
        return null !== self::$isTokenList && (self::$isTokenList)($object);
    }

    public static function tokenListDimensionIsEmpty(ObjectEntry $object, Variable $dim): bool
    {
        if (null === self::$tokenListDimensionIsEmpty) {
            return true;
        }

        return (self::$tokenListDimensionIsEmpty)($object, $dim);
    }

    public static function hasDimension(ObjectEntry $object, Variable $dim): bool
    {
        if (null === self::$hasDimension) {
            return false;
        }

        return (self::$hasDimension)($object, $dim);
    }
}
