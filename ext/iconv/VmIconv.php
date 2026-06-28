<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
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

    public static function iconv(string $fromEncoding, string $toEncoding, string $input, ?Frame $frame = null): string|false
    {
        if (null === CharsetEngine::parseEncodingSpec($fromEncoding)) {
            self::triggerUnsupportedEncodingWarning($frame, $fromEncoding, $toEncoding);

            return false;
        }
        if (null === CharsetEngine::parseEncodingSpec($toEncoding)) {
            self::triggerUnsupportedEncodingWarning($frame, $fromEncoding, $toEncoding);

            return false;
        }

        $result = CharsetEngine::convert($fromEncoding, $toEncoding, $input);
        if (false === $result) {
            self::triggerIllegalCharacterNotice($frame);

            return false;
        }

        return $result;
    }

    private static function triggerIllegalCharacterNotice(?Frame $frame): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            'iconv(): Detected an illegal character in input string',
            ErrorReporter::E_NOTICE,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function triggerUnsupportedEncodingWarning(?Frame $frame, string $fromEncoding, string $toEncoding): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            sprintf(
                'iconv(): Wrong encoding, conversion from "%s" to "%s" is not allowed',
                $fromEncoding,
                $toEncoding
            ),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
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
