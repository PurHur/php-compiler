<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbCheckEncodingRuntime;
use PHPCompiler\JIT\Builtin\StringUtf8Runtime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_check_encoding() (#4571, #35211 runtime encoding).
 *
 * Compile-time fold + UTF-8 literal via StringUtf8Runtime; runtime encoding via NestedJIT
 * {@see MbCheckEncodingJitHelper} (peer {@see JitMbStrlen} / #34625).
 */
final class JitMbCheckEncoding
{
    /**
     * @param JITVariable[] $args
     */
    public static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        $var = self::compileTimeVar($args);
        if (!\array_key_exists('var', $var) && 0 === \count($args)) {
            return $context->constantFromBool(true);
        }
        if (!\array_key_exists('var', $var)) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 1);
        if (null === $encoding && isset($args[1])) {
            return null;
        }
        // Unknown / unsupported encoding → NestedJIT (catchable ValueError) (#35211).
        if (null !== $encoding && null === self::canonicalCheckEncoding($encoding)) {
            return null;
        }

        return $context->constantFromBool(
            VmMbstring::checkEncoding($var['var'], $encoding)
        );
    }

    /**
     * @param JITVariable[] $args
     */
    public static function lowerRuntime(Context $context, array $args): Value
    {
        if (0 === \count($args)) {
            return $context->constantFromBool(true);
        }
        if (isset($args[0]) && self::isArrayArg($args[0])) {
            throw new \LogicException(
                'mb_check_encoding() array argument is not lowered for JIT/AOT in this compiler build'
            );
        }

        $argc = \count($args);
        $encodingLit = null;
        if ($argc >= 2
            && JITVariable::TYPE_NULL !== $args[1]->type
            && !($args[1]->isNullConstant ?? false)
        ) {
            $encodingLit = JitStringArg::compileTimeLiteral($args[1]);
        }

        // Fast path: omitted / null / known UTF-8|ASCII|8BIT literal (#4571).
        if (null === $encodingLit && ($argc < 2
            || JITVariable::TYPE_NULL === $args[1]->type
            || ($args[1]->isNullConstant ?? false))
        ) {
            return self::utf8ValidFromArg($context, $args[0]);
        }
        if (null !== $encodingLit) {
            $canonical = self::canonicalCheckEncoding($encodingLit);
            if ('ASCII' === $canonical || '8BIT' === $canonical) {
                return $context->constantFromBool(true);
            }
            if ('UTF-8' === $canonical) {
                return self::utf8ValidFromArg($context, $args[0]);
            }
            // Invalid / unsupported literal → NestedJIT ValueError (#35211).
        }

        // Runtime encoding (TYPE_VALUE / non-literal TYPE_STRING) — NestedJIT (#35211).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbCheckEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_check_encoding_runtime');

        $str = JitStringBuiltinArg::lower($context, $args[0], 'mb_check_encoding', 0, 'var');
        $enc = null !== $encodingLit
            ? $context->builder->load($context->constantStringFromString($encodingLit))
            : JitStringBuiltinArg::lower(
                $context,
                $args[1],
                'mb_check_encoding',
                1,
                'encoding'
            );

        $ok = $context->builder->call(
            MbCheckEncodingRuntime::checkHelper($context),
            $str,
            $enc
        );
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return $context->builder->icmp(Builder::INT_NE, $ok, $zero);
    }

    private static function utf8ValidFromArg(Context $context, JITVariable $arg): Value
    {
        $str = JitStringBuiltinArg::lower($context, $arg, 'mb_check_encoding', 0, 'var');
        $valid = StringUtf8Runtime::validFromPtr($context, $str);
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return $context->builder->icmp(Builder::INT_NE, $valid, $zero);
    }

    private static function canonicalCheckEncoding(string $encoding): ?string
    {
        $upper = \strtoupper($encoding);
        if ('UTF-8' === $upper || 'UTF8' === $upper) {
            return 'UTF-8';
        }
        if ('ASCII' === $upper || 'US-ASCII' === $upper) {
            return 'ASCII';
        }
        if ('8BIT' === $upper || 'BINARY' === $upper) {
            return '8BIT';
        }

        return null;
    }

    /**
     * @param JITVariable[] $args
     *
     * @return array{var?: array<string>|string|null}
     */
    private static function compileTimeVar(array $args): array
    {
        if (!isset($args[0])) {
            return [];
        }
        if (JITVariable::TYPE_NULL === $args[0]->type) {
            return ['var' => ''];
        }
        if (JITVariable::TYPE_STRING === $args[0]->type && null !== ($args[0]->compileTimeString ?? null)) {
            return ['var' => $args[0]->compileTimeString];
        }
        if (self::isArrayArg($args[0]) && null !== ($args[0]->compileTimeArray ?? null)) {
            $items = [];
            foreach ($args[0]->compileTimeArray as $elem) {
                if (JITVariable::TYPE_STRING !== $elem->type || null === ($elem->compileTimeString ?? null)) {
                    return [];
                }
                $items[] = $elem->compileTimeString;
            }

            return ['var' => $items];
        }

        return [];
    }

    private static function isArrayArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_HASHTABLE === $arg->type
            || (($arg->type & JITVariable::IS_NATIVE_ARRAY) !== 0)
            || ($arg->compileTimeEmptyArrayLiteral ?? false)
            || null !== ($arg->compileTimeArray ?? null);
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeEncoding(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return JitStringArg::compileTimeLiteral($args[$index])
            ?? $args[$index]->compileTimeString
            ?? null;
    }
}
