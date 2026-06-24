<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\StringUtf8Runtime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_check_encoding() (issue #4571).
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
        if (isset($args[0]) && JITVariable::TYPE_ARRAY === $args[0]->type) {
            throw new \LogicException(
                'mb_check_encoding() array argument is not lowered for JIT/AOT in this compiler build'
            );
        }

        $encoding = self::compileTimeEncoding($args, 1);
        if (null === $encoding) {
            throw new \LogicException(
                'mb_check_encoding() JIT requires compile-time encoding literal in this compiler build'
            );
        }
        VmMbstring::assertCheckEncodingName($encoding);

        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return $context->constantFromBool(true);
        }
        if ('UTF-8' !== $encoding) {
            throw new \LogicException(
                'mb_check_encoding() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }

        $str = JitStringBuiltinArg::lower($context, $args[0], 'mb_check_encoding', 0, 'var');
        $valid = StringUtf8Runtime::validFromPtr($context, $str);
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return $context->builder->icmp(Builder::INT_NE, $valid, $zero);
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
        if (JITVariable::TYPE_ARRAY === $args[0]->type && null !== ($args[0]->compileTimeArray ?? null)) {
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

        return $args[$index]->compileTimeString ?? null;
    }
}
