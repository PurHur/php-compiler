<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT compile-time folding for mbstring search builtins (#7015).
 */
final class JitMbSearch
{
    /**
     * @param JITVariable[] $args
     */
    public static function tryStriposFold(Context $context, array $args): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $offset = self::compileTimeOffset($args, 2);
        if (null === $offset) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 3);
        if (null === $encoding) {
            return null;
        }

        return self::intOrFalse($context, VmMbstring::stripos($hay, $needle, $offset, $encoding));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrrposFold(Context $context, array $args): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $offset = self::compileTimeOffset($args, 2);
        if (null === $offset) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 3);
        if (null === $encoding) {
            return null;
        }

        return self::intOrFalse($context, VmMbstring::strrpos($hay, $needle, $offset, $encoding));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrrichrFold(Context $context, array $args): ?Value
    {
        return self::tryStrchrFamilyFold($context, $args, static function (
            string $hay,
            string $needle,
            bool $part,
            string $encoding
        ) {
            return VmMbstring::strrichr($hay, $needle, $part, $encoding);
        });
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrstrFold(Context $context, array $args): ?Value
    {
        return self::tryStrchrFamilyFold($context, $args, static function (
            string $hay,
            string $needle,
            bool $part,
            string $encoding
        ) {
            return VmMbstring::strstr($hay, $needle, $part, $encoding);
        });
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStristrFold(Context $context, array $args): ?Value
    {
        return self::tryStrchrFamilyFold($context, $args, static function (
            string $hay,
            string $needle,
            bool $part,
            string $encoding
        ) {
            return VmMbstring::stristr($hay, $needle, $part, $encoding);
        });
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrrchrFold(Context $context, array $args): ?Value
    {
        return self::tryStrchrFamilyFold($context, $args, static function (
            string $hay,
            string $needle,
            bool $part,
            string $encoding
        ) {
            return VmMbstring::strrchr($hay, $needle, $part, $encoding);
        });
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrriposFold(Context $context, array $args): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $offset = self::compileTimeOffset($args, 2);
        if (null === $offset) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 3);
        if (null === $encoding) {
            return null;
        }

        return self::intOrFalse($context, VmMbstring::strripos($hay, $needle, $offset, $encoding));
    }

    /**
     * @param JITVariable[] $args
     * @param callable(string, string, bool, string): (string|false) $compute
     */
    private static function tryStrchrFamilyFold(Context $context, array $args, callable $compute): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $part = self::compileTimePart($args, 2);
        if (null === $part) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 3);
        if (null === $encoding) {
            return null;
        }
        $result = $compute($hay, $needle, $part, $encoding);
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeString(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return null;
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeOffset(array $args, int $index): ?int
    {
        if (!isset($args[$index])) {
            return 0;
        }
        $arg = $args[$index];
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return (int) $const->constInt();
            }
        }

        return null;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimePart(array $args, int $index): ?bool
    {
        if (!isset($args[$index])) {
            return false;
        }
        $arg = $args[$index];
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return 0 !== (int) $const->constInt();
            }
        }

        return null;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeEncoding(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }

    /**
     * @param int|false $result
     */
    private static function intOrFalse(Context $context, int|false $result): Value
    {
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->constantFromInteger($result, 'int64');
    }
}
