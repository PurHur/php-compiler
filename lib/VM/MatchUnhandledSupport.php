<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmIni;
use PHPCompiler\ext\standard\VmSerializeFormat;

/**
 * UnhandledMatchError message formatting — php-src zend_match_unhandled_error (#23664).
 *
 * php-src: Zend/zend_execute.c — zend_match_unhandled_error()
 * php-src: Zend/zend_smart_str.c — smart_str_append_zval / smart_str_append_scalar
 *   Enums: EnumName::CaseName (ZEND_ACC_ENUM), not "of type EnumName" (#29248).
 */
final class MatchUnhandledSupport
{
    /**
     * Full exception message: "Unhandled match case …".
     */
    public static function formatMessage(Variable $value): string
    {
        return 'Unhandled match case '.self::formatCaseSuffix($value);
    }

    /**
     * Suffix after "Unhandled match case " (scalar, Enum::Case, or "of type …").
     */
    public static function formatCaseSuffix(Variable $value): string
    {
        $value = $value->resolveIndirect();

        // Zend smart_str_append_zval: scalars + enum objects; else "of type …".
        if (ResourceSupport::isVmResource($value)) {
            return 'of type resource';
        }

        $enumEntry = EnumCaseSupport::enumCaseEntryForVariable($value);
        if (null !== $enumEntry) {
            return $enumEntry->enumClass->name.'::'.$enumEntry->caseName;
        }

        switch ($value->type) {
            case Variable::TYPE_NULL:
                return 'NULL';
            case Variable::TYPE_BOOLEAN:
                return $value->toBool() ? 'true' : 'false';
            case Variable::TYPE_INTEGER:
                return (string) $value->toInt();
            case Variable::TYPE_FLOAT:
                return self::formatFloat($value->toFloat());
            case Variable::TYPE_STRING:
                return self::formatString($value->toString());
            case Variable::TYPE_ARRAY:
                return 'of type array';
            case Variable::TYPE_OBJECT:
                return 'of type '.$value->toObject()->class->name;
            case Variable::TYPE_ENUM_CASE:
                // enumCaseEntryForVariable should have handled this; keep a safe label.
                return 'of type '.$value->toEnumCase()->enumClass->name;
            default:
                return 'of type unknown type';
        }
    }

    /**
     * php-src smart_str_append_scalar string arm (EG(exception_string_param_max_len)).
     */
    public static function formatString(string $value): string
    {
        return SensitiveParamSupport::formatExceptionStringParam($value);
    }

    /**
     * php-src smart_str_append_double(..., EG(precision), zero_fraction=true).
     */
    public static function formatFloat(float $num): string
    {
        if (\is_nan($num)) {
            return 'NAN';
        }
        if (\is_infinite($num)) {
            return $num > 0.0 ? 'INF' : '-INF';
        }
        $formatted = VmSerializeFormat::formatDoubleWithPrecision($num, VmIni::getPrecision());
        if (\is_finite($num) && false === \strpos($formatted, '.') && false === \stripos($formatted, 'e')) {
            $formatted .= '.0';
        }

        return $formatted;
    }
}
