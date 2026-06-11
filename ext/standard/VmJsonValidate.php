<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * json_validate() VM helper — native scanner (VmJsonScanner), no host json_validate/json_decode.
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
        if (VmJsonScanner::RESULT_DEPTH === $result) {
            throw new \ValueError('json_validate(): Argument #1 ($json) depth exceeds the maximum allowed depth of '.$depth);
        }
        if (VmJsonScanner::RESULT_VALID === $result) {
            VmJson::setLastError(0);

            return true;
        }
        VmJson::setLastError(4);

        return false;
    }
}
