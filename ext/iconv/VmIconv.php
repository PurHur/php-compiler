<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * VM iconv() helpers without host \\iconv() (issue #6251).
 *
 * php-src: ext/iconv/iconv.c
 */
final class VmIconv
{
    public static function coerceEncodingArg(Variable $var, string $function, int $argIndex, string $param): string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type string, %s given',
                $function,
                $argIndex + 1,
                $param,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type string, %s given',
                $function,
                $argIndex + 1,
                $param,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    public static function iconv(string $fromEncoding, string $toEncoding, string $input): string|false
    {
        if (null === CharsetEngine::parseEncodingSpec($fromEncoding)) {
            throw new \ValueError(sprintf(
                'iconv(): Argument #1 ($from_encoding) is not a supported encoding, "%s" given',
                $fromEncoding
            ));
        }
        if (null === CharsetEngine::parseEncodingSpec($toEncoding)) {
            throw new \ValueError(sprintf(
                'iconv(): Argument #2 ($to_encoding) is not a supported encoding, "%s" given',
                $toEncoding
            ));
        }

        return CharsetEngine::convert($fromEncoding, $toEncoding, $input);
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOL => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
