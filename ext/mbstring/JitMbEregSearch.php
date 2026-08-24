<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Compile-time fold for mb_ereg* / mb_ereg_search_* / mb_regex_encoding (#30781, #33648, #33655, #33656, #33765).
 *
 * Same shape as {@see JitMbSearch} / {@see mb_internal_encoding}: literals only;
 * search cursor lives in {@see MbstringState} for the duration of one AOT/JIT
 * compile so init → search sequences fold correctly.
 */
final class JitMbEregSearch
{
    /**
     * mb_ereg() / mb_eregi() — 2-arg literal fold (no &$regs). php-src php_mbregex.c (#33648).
     *
     * @param JITVariable[] $args
     */
    public static function tryEregFold(
        Context $context,
        array $args,
        bool $caseInsensitive
    ): ?Value {
        if (2 !== \count($args)) {
            return null;
        }
        $pattern = JitStringArg::compileTimeLiteral($args[0]);
        $string = JitStringArg::compileTimeLiteral($args[1]);
        if (null === $pattern || null === $string) {
            return null;
        }

        MbstringAotFoldState::syncRegexEncodingIntoState($context);
        $out = VmMbstring::eregMatch($pattern, $string, $caseInsensitive);

        // Boxed bool — same convention as mb_ord foldFalse / ExceptionBridge catchables (#33648).
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool(
            $context,
            $slot,
            $i1->constInt($out['matched'] ? 1 : 0, false)
        );

        return JitValueBox::pointer($context, $slot);
    }

    /**
     * mb_ereg_match() — 2–3 arg literal fold (anchored). php-src php_mbregex.c (#33655).
     *
     * @param JITVariable[] $args
     */
    public static function tryEregMatchFold(Context $context, array $args): ?Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            return null;
        }
        $pattern = JitStringArg::compileTimeLiteral($args[0]);
        $string = JitStringArg::compileTimeLiteral($args[1]);
        if (null === $pattern || null === $string) {
            return null;
        }
        $options = null;
        if (3 === $argc) {
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                $options = null;
            } else {
                $options = JitStringArg::compileTimeLiteral($args[2]);
                if (null === $options) {
                    return null;
                }
            }
        }

        MbstringAotFoldState::syncRegexEncodingIntoState($context);
        $matched = VmMbstring::eregMatchAnchored($pattern, $string, $options);

        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool(
            $context,
            $slot,
            $i1->constInt($matched ? 1 : 0, false)
        );

        return JitValueBox::pointer($context, $slot);
    }

    /**
     * mb_ereg_replace() / mb_eregi_replace() — 3-arg literal fold (no $options).
     * php-src php_mbregex.c (#33656 / #33765).
     *
     * @param JITVariable[] $args
     */
    public static function tryEregReplaceFold(
        Context $context,
        array $args,
        bool $caseInsensitive
    ): ?Value {
        if (3 !== \count($args)) {
            return null;
        }
        $pattern = JitStringArg::compileTimeLiteral($args[0]);
        $replacement = JitStringArg::compileTimeLiteral($args[1]);
        $string = JitStringArg::compileTimeLiteral($args[2]);
        if (null === $pattern || null === $replacement || null === $string) {
            return null;
        }

        MbstringAotFoldState::syncRegexEncodingIntoState($context);
        $result = VmMbstring::eregReplace($pattern, $replacement, $string, $caseInsensitive);
        if (!\is_string($result)) {
            // false/null — leave non-foldable; NestedJIT runtime via {@see JitMbEreg::invokeReplace}.
            return null;
        }

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function foldRegexEncoding(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_regex_encoding() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (0 === $argc
            || (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            $enc = MbstringAotFoldState::regexEncoding($context)
                ?? (string) MbstringState::regexEncoding();

            return $context->builder->load($context->constantStringFromString($enc));
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $encodingLit) {
            throw new \LogicException(
                'mb_regex_encoding() encoding must be a compile-time string in this compiler build'
            );
        }
        $valid = MbstringEncodingRegistry::assertValid($encodingLit, 'mb_regex_encoding', 0);
        MbstringAotFoldState::setRegexEncoding($context, $valid);
        MbstringState::regexEncoding($valid);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    /**
     * @param JITVariable[] $args
     */
    public static function foldSearchInit(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_init() expects at least 1 argument, %d given',
                $argc
            ));
        }
        $string = self::requireLiteralString($args[0], 'mb_ereg_search_init', 'string');
        $pattern = null;
        if (isset($args[1])) {
            $pattern = self::optionalLiteralString($args[1], 'mb_ereg_search_init', 'pattern');
        }
        $options = null;
        if (isset($args[2])) {
            $options = self::optionalLiteralString($args[2], 'mb_ereg_search_init', 'options');
        }

        MbstringAotFoldState::syncRegexEncodingIntoState($context);
        $ok = VmMbstring::eregSearchInit($string, $pattern, $options);

        return $context->getTypeFromString('int1')->constInt($ok ? 1 : 0, false);
    }

    /**
     * @param JITVariable[] $args
     */
    public static function foldSearch(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        $pattern = null;
        if (isset($args[0])) {
            $pattern = self::optionalLiteralString($args[0], 'mb_ereg_search', 'pattern');
        }
        $options = null;
        if (isset($args[1])) {
            $options = self::optionalLiteralString($args[1], 'mb_ereg_search', 'options');
        }

        MbstringAotFoldState::syncRegexEncodingIntoState($context);
        $out = VmMbstring::eregSearchExec(0, $pattern, $options);

        return $context->getTypeFromString('int1')->constInt($out ? 1 : 0, false);
    }

    private static function requireLiteralString(
        JITVariable $arg,
        string $function,
        string $param
    ): string {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type string, null given',
                $function,
                1,
                $param
            ));
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null === $lit) {
            throw new \LogicException(sprintf(
                '%s() $%s must be a compile-time string in this compiler build',
                $function,
                $param
            ));
        }

        return $lit;
    }

    private static function optionalLiteralString(
        JITVariable $arg,
        string $function,
        string $param
    ): ?string {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return null;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null === $lit) {
            throw new \LogicException(sprintf(
                '%s() $%s must be a compile-time string in this compiler build',
                $function,
                $param
            ));
        }

        return $lit;
    }
}
