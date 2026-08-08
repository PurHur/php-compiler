<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * json_validate() / json_last_error*() NestedJIT helpers (#9359, #20829).
 *
 * Split from {@see JsonDecodeJitHelper} so validate NestedJIT stays independent of decode.
 * php-src: ext/json/php_json.c
 *
 * AOT NestedJIT TUs do not share PHP statics across helpers — keep a mirror of JSON_G(error_code)
 * in this TU so compile-time encode fold / last_error* agree (#26792).
 */
final class JsonValidateJitHelper
{
    /** NestedJIT-local mirror of JSON last-error (AOT TU isolation, #26792). */
    private static int $lastError = 0;

    /** @return int 1 valid, 0 syntax error, -1 depth exceeded */
    public static function validate(string $json, int $depth, int $flags = 0): int
    {
        if ($depth < 1) {
            self::setLastError(1);

            return VmJsonScanner::RESULT_SYNTAX;
        }
        // Caller (VM / json_validate::call) must enforce Zend 0|IGNORE (#29069); NestedJIT
        // cannot lower catchable ValueError throws from this TU reliably.

        $result = VmJsonScanner::validate($json, $depth, $flags);
        self::setLastError(VmJson::lastError());

        return $result;
    }

    /** @return int echoed $code (int return — NestedJIT void ABI is unreliable) */
    public static function setLastError(int $code): int
    {
        self::$lastError = $code;

        return $code;
    }

    public static function lastError(): int
    {
        return self::$lastError;
    }

    /**
     * Inline message table (PregJitHelper::lastErrorMsg peer) so NestedJIT returns a native
     * string inside this TU — delegating to VmJson::lastErrorMsg() segfaults under AOT (#26792).
     *
     * Codes match {@see VmJson::errorMsgForCode()} / php-src ext/json/php_json.h.
     */
    public static function lastErrorMsg(): string
    {
        $code = self::$lastError;
        if (0 === $code) {
            return 'No error';
        }
        if (1 === $code) {
            return 'Maximum stack depth exceeded';
        }
        if (4 === $code) {
            return 'Syntax error';
        }
        if (5 === $code) {
            return 'Malformed UTF-8 characters, possibly incorrectly encoded';
        }
        if (6 === $code) {
            return 'Recursion detected';
        }
        if (7 === $code) {
            return 'Inf and NaN cannot be JSON encoded';
        }
        if (8 === $code) {
            return 'Type is not supported';
        }
        if (10 === $code) {
            return 'Single unpaired UTF-16 surrogate in unicode escape';
        }
        if (11 === $code) {
            return 'Non-backed enums have no default serialization';
        }

        return 'Unknown error';
    }
}
