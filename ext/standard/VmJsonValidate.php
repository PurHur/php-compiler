<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * json_validate() VM helper — native scanner (VmJsonScanner), no host json_validate/json_decode.
 *
 * php-src: ext/json/json.c — php_json_validate_ex (depth exceed → false + JSON_ERROR_DEPTH, not ValueError).
 */
final class VmJsonValidate
{
    public static function validate(string $json, int $depth, int $flags = 0): bool
    {
        if ($depth < 1) {
            throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
        }
        VmJsonFlags::assertValidateFlags($flags);
        $result = VmJsonScanner::validate($json, $depth, $flags);
        if (VmJsonScanner::RESULT_VALID === $result) {
            VmJson::setLastError(0);

            return true;
        }
        if (VmJsonScanner::RESULT_DEPTH === $result) {
            // Scanner already set JSON_ERROR_DEPTH (1); match Zend — return false, do not throw.
            VmJson::setLastError(1);

            return false;
        }
        VmJson::setLastError(4);

        return false;
    }
}
