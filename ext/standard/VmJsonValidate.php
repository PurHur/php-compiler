<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * json_validate() VM helper — host json_validate (PHP 8.3+) or json_decode depth fallback.
 */
final class VmJsonValidate
{
    public static function validate(string $json, int $depth): bool
    {
        if ($depth < 1) {
            throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
        }
        if (\function_exists('json_validate')) {
            return \json_validate($json, $depth);
        }
        \json_decode($json, true, $depth);
        $err = \json_last_error();
        if (\JSON_ERROR_DEPTH === $err) {
            throw new \ValueError('json_validate(): Argument #1 ($json) depth exceeds the maximum allowed depth of '.$depth);
        }

        return \JSON_ERROR_NONE === $err;
    }
}
