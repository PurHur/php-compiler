<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Compile-time fold for mb_ereg* / mb_ereg_search_* / mb_regex_encoding /
 * mb_regex_set_options (#30781, #30873, #33648, #33655, #33656, #33765, #34424, #34438).
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
            // false/null — leave non-foldable; runtime uses NestedJIT (#34389).
            return null;
        }

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function foldRegexEncoding(Context $context, array $args): Value
    {
        // Runtime + compile-time via NestedJIT module global (#35284 / peer #35221).
        return JitMbRegexEncoding::invoke($context, $args);
    }

    /**
     * mb_regex_set_options() — get/set default mbregex options (#34438 / #4635).
     *
     * Peer {@see foldRegexEncoding}: compile-time literals only. Returns the previous
     * (normalized) option string — php-src php_mbregex.c PHP_FUNCTION(mb_regex_set_options).
     *
     * @param JITVariable[] $args
     */
    public static function foldRegexSetOptions(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_regex_set_options() expects at most 1 argument, %d given',
                $argc
            ));
        }
        $current = MbstringAotFoldState::regexOptions($context)
            ?? MbstringState::regexOptions();
        if (0 === $argc
            || (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            return $context->builder->load($context->constantStringFromString($current));
        }

        $optionsLit = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $optionsLit) {
            throw new \LogicException(
                'mb_regex_set_options() options must be a compile-time string in this compiler build'
            );
        }
        // ValueError on unsupported chars — same as Zend / VM execute path.
        $normalized = MbRegexOptions::normalize($optionsLit);
        MbstringAotFoldState::setRegexOptions($context, $normalized);
        MbstringState::regexOptions($normalized);

        return $context->builder->load($context->constantStringFromString($current));
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

    /**
     * mb_ereg_search_pos() — mode 1: [offset, length] or false (#34424).
     *
     * @param JITVariable[] $args
     */
    public static function foldSearchPos(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_pos() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        $pattern = null;
        if (isset($args[0])) {
            $pattern = self::optionalLiteralString($args[0], 'mb_ereg_search_pos', 'pattern');
        }
        $options = null;
        if (isset($args[1])) {
            $options = self::optionalLiteralString($args[1], 'mb_ereg_search_pos', 'options');
        }

        MbstringAotFoldState::syncRegexEncodingIntoState($context);
        $out = VmMbstring::eregSearchExec(1, $pattern, $options);
        if (false === $out) {
            return self::boxedFalse($context);
        }
        /** @var array{0: int, 1: int} $out */
        $ht = VmMbstring::searchPosPairToHashTable($out);
        $cacheKey = 'mb_ereg_search_pos_'.$out[0].'_'.$out[1];

        return self::boxedConstantArray($context, $cacheKey, $ht);
    }

    /**
     * mb_ereg_search_regs() — mode 2: capture regs or false (#34424).
     *
     * @param JITVariable[] $args
     */
    public static function foldSearchRegs(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_regs() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        $pattern = null;
        if (isset($args[0])) {
            $pattern = self::optionalLiteralString($args[0], 'mb_ereg_search_regs', 'pattern');
        }
        $options = null;
        if (isset($args[1])) {
            $options = self::optionalLiteralString($args[1], 'mb_ereg_search_regs', 'options');
        }

        MbstringAotFoldState::syncRegexEncodingIntoState($context);
        $out = VmMbstring::eregSearchExec(2, $pattern, $options);
        if (false === $out) {
            return self::boxedFalse($context);
        }
        /** @var array<int, string|false> $out */
        $ht = VmMbstring::mbRegsToHashTable($out);
        $cacheKey = 'mb_ereg_search_regs_'.md5(serialize($out));

        return self::boxedConstantArray($context, $cacheKey, $ht);
    }

    /**
     * mb_ereg_search_getpos() — current search byte offset (#34424).
     *
     * @param JITVariable[] $args
     */
    public static function foldSearchGetPos(Context $context, array $args): Value
    {
        if (\count($args) > 0) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_getpos() expects exactly 0 arguments, %d given',
                \count($args)
            ));
        }

        return $context->getTypeFromString('int64')->constInt(MbstringState::searchPos(), true);
    }

    /**
     * mb_ereg_search_getregs() — last search captures (#34424).
     *
     * @param JITVariable[] $args
     */
    public static function foldSearchGetRegs(Context $context, array $args): Value
    {
        if (\count($args) > 0) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_getregs() expects exactly 0 arguments, %d given',
                \count($args)
            ));
        }

        $regs = VmMbstring::eregSearchGetRegs();
        if (false === $regs) {
            return self::boxedFalse($context);
        }
        $ht = VmMbstring::mbRegsToHashTable($regs);
        $cacheKey = 'mb_ereg_search_getregs_'.md5(serialize($regs));

        return self::boxedConstantArray($context, $cacheKey, $ht);
    }

    /**
     * mb_ereg_search_setpos() — set search byte offset (#34424).
     *
     * @param JITVariable[] $args
     */
    public static function foldSearchSetPos(Context $context, array $args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_search_setpos() expects exactly 1 argument, %d given',
                \count($args)
            ));
        }
        $offset = self::compileTimeInt($context, $args[0]);
        if (null === $offset) {
            throw new \LogicException(
                'mb_ereg_search_setpos() offset must be a compile-time integer in this compiler build'
            );
        }

        $ok = VmMbstring::eregSearchSetPos($offset);

        return $context->getTypeFromString('int1')->constInt($ok ? 1 : 0, false);
    }

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxedConstantArray(
        Context $context,
        string $cacheKey,
        \PHPCompiler\VM\HashTable $ht
    ): Value {
        $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

        return $slot;
    }

    /** Peer {@see mb_substr::compileTimeInt} — literal int64 ConstantInt (#34424). */
    private static function compileTimeInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
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
