<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for date_parse() / date_parse_from_format() (#6172).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_parse), PHP_FUNCTION(date_parse_from_format)
 */
final class JitDateParse
{
    public static function invokeDateParse(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('date_parse() expects exactly 1 argument in this compiler build');
        }
        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#24862; peer idate #21491).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'date_parse', 0, 'datetime');
            } else {
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'date_parse', 0, 'datetime');
            }
            $lit = '';
        } else {
            $lit = self::compileTimeStringArg($args[0]);
            if (null === $lit) {
                throw new \LogicException(
                    'date_parse() requires compile-time string operands in this compiler build (issue #6172)'
                );
            }
        }

        $parsed = VmDateTimeNative::parseDate($lit);
        $ht = JitDateParseMaterializer::materialize($context, $parsed);

        return self::wrapHashtable($context, $ht);
    }

    public static function invokeDateParseFromFormat(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('date_parse_from_format() expects exactly 2 arguments in this compiler build');
        }
        // Z_PARAM_STR — caller strict_types → TypeError on null (#30308).
        // Soft-null (non-strict) still lowers via lowerZparamStr → DEP+coerce to "".
        $formatIsNull = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        $dateIsNull = JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false);
        if ($formatIsNull) {
            JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'date_parse_from_format', 0, 'format');
            $formatLit = '';
        } else {
            $formatLit = self::compileTimeStringArg($args[0]);
            if (null === $formatLit) {
                throw new \LogicException(
                    'date_parse_from_format() requires compile-time string operands in this compiler build (issue #6172)'
                );
            }
        }
        if ($dateIsNull) {
            JitStringBuiltinArg::lowerZparamStr($context, $args[1], 'date_parse_from_format', 1, 'datetime');
            $dateLit = '';
        } else {
            $dateLit = self::compileTimeStringArg($args[1]);
            if (null === $dateLit) {
                throw new \LogicException(
                    'date_parse_from_format() requires compile-time string operands in this compiler build (issue #6172)'
                );
            }
        }

        $parsed = VmDateTimeNative::parseFromFormatComponents($formatLit, $dateLit);
        $ht = JitDateParseMaterializer::materialize($context, $parsed);

        return self::wrapHashtable($context, $ht);
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
    }

    private static function wrapHashtable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }
}
