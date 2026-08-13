<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT compile-time folding for mb_chr() / mb_ord() (php-src ext/mbstring/mbstring.c; #30759).
 *
 * Peer {@see JitMbSearch} / {@see JitMbUcfirstLcfirst}: fold literal sites via {@see VmMbstring}.
 */
final class JitMbChrOrd
{
    /**
     * @param JITVariable[] $args
     */
    public static function tryChrFold(Context $context, array $args): ?Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            return null;
        }
        $codepoint = self::compileTimeCodepoint($context, $args[0]);
        if (null === $codepoint) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 1);
        if (null === $encoding) {
            return null;
        }
        // Unknown encoding → fall through (avoid ValueError during IR fold; peer #23883).
        if (!MbstringEncodingRegistry::isValid($encoding)) {
            return null;
        }

        return self::stringOrFalse($context, VmMbstring::chr($codepoint, $encoding));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryOrdFold(Context $context, array $args): ?Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            return null;
        }
        if (
            JITVariable::TYPE_STRING !== $args[0]->type
            || null === ($args[0]->compileTimeString ?? null)
        ) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 1);
        if (null === $encoding) {
            return null;
        }
        if (!MbstringEncodingRegistry::isValid($encoding)) {
            return null;
        }

        return self::intOrFalse($context, VmMbstring::ord($args[0]->compileTimeString, $encoding));
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
            return null;
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }

    private static function compileTimeCodepoint(Context $context, JITVariable $arg): ?int
    {
        if (null !== ($arg->compileTimeLong ?? null)) {
            return $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        // Prefer LLVMIsAConstantInt — Value::isConstant()/constInt() miss some AOT i64 literals (#27187).
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
    }

    /**
     * @param string|false $result
     */
    private static function stringOrFalse(Context $context, string|false $result): Value
    {
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->builder->load($context->constantStringFromString($result));
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
