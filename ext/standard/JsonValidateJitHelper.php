<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * json_validate() / json_last_error*() NestedJIT helpers (#9359, #20829).
 *
 * Split from {@see JsonDecodeJitHelper} so validate NestedJIT stays independent of decode.
 * php-src: ext/json/php_json.c
 */
final class JsonValidateJitHelper
{
    /** @return int 1 valid, 0 syntax error, -1 depth exceeded */
    public static function validate(string $json, int $depth): int
    {
        if ($depth < 1) {
            return VmJsonScanner::RESULT_SYNTAX;
        }

        return VmJsonScanner::validate($json, $depth, 0);
    }

    public static function lastError(): int
    {
        return VmJson::lastError();
    }

    public static function lastErrorMsg(): string
    {
        return VmJson::lastErrorMsg();
    }
}
