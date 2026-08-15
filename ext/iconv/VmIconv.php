<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\ext\standard\VmNullStringParamDeprecation;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/**
 * VM iconv() helpers without host \\iconv() (issue #6251).
 *
 * php-src: ext/iconv/iconv.c
 */
final class VmIconv
{
    public static function coerceEncodingArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $param,
        ?Frame $frame = null
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            // Z_PARAM_STR — null TypeError on 8.4 forward profile (#19387, re-#18993; iconv.c).
            // Default profile: soft-null E_DEPRECATED then coerce to "" (#31309).
            if (
                VmString::requiresZparamStrStrictNullOnForwardProfile()
                || (null !== $frame && InternalStrictArg::isCallerStrict($frame))
            ) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #%d ($%s) must be of type string, null given',
                    $function,
                    $argIndex + 1,
                    $param
                ));
            }
            VmNullStringParamDeprecation::emit($frame, $function, $argIndex, $param);

            return '';
        }
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

    /**
     * php-src ext/iconv/iconv.c — empty/null encoding uses input/output ini defaults.
     */
    public static function resolveIconvEncoding(string $encoding, bool $isFrom): string
    {
        if ('' !== $encoding) {
            return $encoding;
        }

        return $isFrom
            ? IconvEncodingState::getInputEncoding()
            : IconvEncodingState::getOutputEncoding();
    }

    /**
     * Optional $encoding on iconv_strlen / substr / strpos / strrpos.
     *
     * php-src: NULL → get_internal_encoding(); "" is passed through to iconv (locale
     * codeset on glibc). This port maps both to internal_encoding (#29497).
     */
    public static function resolveOptionalEncoding(string $encoding): string
    {
        if ('' !== $encoding) {
            return $encoding;
        }

        return IconvEncodingState::getInternalEncoding();
    }

    public static function iconv(string $fromEncoding, string $toEncoding, string $input, ?Frame $frame = null): string|false
    {
        $fromEncoding = self::resolveIconvEncoding($fromEncoding, true);
        $toEncoding = self::resolveIconvEncoding($toEncoding, false);
        if (null === CharsetEngine::parseEncodingSpec($fromEncoding)) {
            self::triggerUnsupportedEncodingWarning($frame, 'iconv', $fromEncoding, $toEncoding);

            return false;
        }
        if (null === CharsetEngine::parseEncodingSpec($toEncoding)) {
            self::triggerUnsupportedEncodingWarning($frame, 'iconv', $fromEncoding, $toEncoding);

            return false;
        }

        $result = CharsetEngine::convert($fromEncoding, $toEncoding, $input);
        if (false === $result) {
            self::triggerConvertNotice($frame, 'iconv', CharsetEngine::lastError());

            return false;
        }

        return $result;
    }

    /**
     * php-src ext/iconv/iconv.c — notice prefix is the calling builtin (iconv / iconv_strlen / …).
     */
    private static function triggerConvertNotice(?Frame $frame, string $function, int $errorKind): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $message = CharsetEngine::ERROR_INCOMPLETE === $errorKind
            ? sprintf('%s(): Detected an incomplete multibyte character in input string', $function)
            : sprintf('%s(): Detected an illegal character in input string', $function);
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_NOTICE,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    /**
     * php-src php_iconv_string helpers report conversion toward UCS-4LE for length/search ops.
     */
    private static function triggerUnsupportedEncodingWarning(
        ?Frame $frame,
        string $function,
        string $fromEncoding,
        string $toEncoding
    ): void {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            sprintf(
                '%s(): Wrong encoding, conversion from "%s" to "%s" is not allowed',
                $function,
                $fromEncoding,
                $toEncoding
            ),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    public static function coerceOptionalEncodingArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $param = 'encoding',
        ?Frame $frame = null
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return IconvEncodingState::getInternalEncoding();
        }

        return self::resolveOptionalEncoding(
            self::coerceEncodingArg($var, $function, $argIndex, $param, $frame)
        );
    }

    /** UCS-4LE is the internal width used by php-src _php_iconv_strlen / strpos family. */
    private const HELPER_WIDTH_ENCODING = 'UCS-4LE';

    public static function iconvStrlen(string $input, string $encoding, ?Frame $frame = null): int|false
    {
        $encoding = self::resolveOptionalEncoding($encoding);
        if (null === CharsetEngine::parseEncodingSpec($encoding)) {
            self::triggerUnsupportedEncodingWarning($frame, 'iconv_strlen', $encoding, self::HELPER_WIDTH_ENCODING);

            return false;
        }
        if (null === CharsetString::splitCharacters($encoding, $input)) {
            // Classify incomplete vs illegal via same-charset convert (php-src iconv.c).
            CharsetEngine::convert($encoding, $encoding, $input);
            self::triggerConvertNotice($frame, 'iconv_strlen', CharsetEngine::lastError());

            return false;
        }

        return CharsetString::strlen($encoding, $input);
    }

    public static function iconvSubstr(
        string $input,
        int $offset,
        ?int $length,
        string $encoding,
        ?Frame $frame = null
    ): string|false {
        $encoding = self::resolveOptionalEncoding($encoding);
        if (null === CharsetEngine::parseEncodingSpec($encoding)) {
            self::triggerUnsupportedEncodingWarning($frame, 'iconv_substr', $encoding, self::HELPER_WIDTH_ENCODING);

            return false;
        }
        if (null === CharsetString::splitCharacters($encoding, $input)) {
            CharsetEngine::convert($encoding, $encoding, $input);
            self::triggerConvertNotice($frame, 'iconv_substr', CharsetEngine::lastError());

            return false;
        }

        return CharsetString::substr($encoding, $input, $offset, $length);
    }

    public static function iconvStrpos(
        string $haystack,
        string $needle,
        int $offset,
        string $encoding,
        ?Frame $frame = null
    ): int|false {
        $encoding = self::resolveOptionalEncoding($encoding);
        if (null === CharsetEngine::parseEncodingSpec($encoding)) {
            self::triggerUnsupportedEncodingWarning($frame, 'iconv_strpos', $encoding, self::HELPER_WIDTH_ENCODING);

            return false;
        }
        // php-src: invalid haystack → false with no illegal/incomplete notice (iconv_strpos).
        if (null === CharsetString::splitCharacters($encoding, $haystack)) {
            return false;
        }

        return CharsetString::strpos($encoding, $haystack, $needle, $offset);
    }

    public static function iconvStrrpos(
        string $haystack,
        string $needle,
        string $encoding,
        ?Frame $frame = null
    ): int|false {
        $encoding = self::resolveOptionalEncoding($encoding);
        if (null === CharsetEngine::parseEncodingSpec($encoding)) {
            self::triggerUnsupportedEncodingWarning($frame, 'iconv_strrpos', $encoding, self::HELPER_WIDTH_ENCODING);

            return false;
        }
        // php-src: invalid haystack → false with no illegal/incomplete notice (iconv_strrpos).
        if (null === CharsetString::splitCharacters($encoding, $haystack)) {
            return false;
        }

        return CharsetString::strrpos($encoding, $haystack, $needle);
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
