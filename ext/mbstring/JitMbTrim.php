<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_trim() / mb_ltrim() / mb_rtrim() (php-src ext/mbstring/mbstring.c; #5957, #9208, #23883).
 */
final class JitMbTrim
{
    /**
     * @param JITVariable[] $args
     */
    public static function invoke(Context $context, int $mode, string $function, array $args): Value
    {
        $folded = self::tryCompileTimeFold($context, $mode, $function, $args);
        if (null !== $folded) {
            return $folded;
        }

        // Same fallback shape as mb_strtoupper::call — cli/JIT recovers via VM execute (#23883).
        throw new \LogicException(
            $function.'() is not lowered for JIT/AOT in this compiler build'
        );
    }

    /**
     * @param JITVariable[] $args
     */
    private static function tryCompileTimeFold(Context $context, int $mode, string $function, array $args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($string) must be of type string, null given',
                $function
            ));
        }
        $string = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString ?? null;
        if (null === $string) {
            return null;
        }
        $what = self::compileTimeWhat($args, 1);
        if (false === $what) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 2);
        if (null === $encoding) {
            return null;
        }

        // Unknown encoding → do not throw ValueError during IR fold (breaks try/catch).
        // Fall through to VM execute via invoke()'s "not lowered" LogicException (#23883).
        if (!MbstringEncodingRegistry::isValid($encoding)) {
            return null;
        }

        return self::materializeString(
            $context,
            VmMbstring::trimString($string, $what, $encoding, $mode, $function)
        );
    }

    /**
     * @param JITVariable[] $args
     *
     * @return null|string|false null = use default trim set; false = not foldable
     */
    private static function compileTimeWhat(array $args, int $index): null|string|false
    {
        if (!isset($args[$index])) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type || ($args[$index]->isNullConstant ?? false)) {
            return null;
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return false;
        }

        return $args[$index]->compileTimeString ?? false;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeEncoding(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type || ($args[$index]->isNullConstant ?? false)) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }

    private static function materializeString(Context $context, string $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
