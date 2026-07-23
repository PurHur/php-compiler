<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simdjson;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmJsonFormat;
use PHPCompiler\ext\standard\VmJsonScanner;

/**
 * PECL simdjson_decode()/simdjson_is_valid() — reuse in-tree JSON semantics (#22530).
 *
 * php-src/PECL ref: awesomized/simdjson_php (simdjson_decode / simdjson_is_valid).
 * Correctness-first MVP: {@see VmJsonFormat} / {@see VmJsonScanner}, not the simdjson C library.
 */
final class VmSimdjson
{
    /**
     * @return array<mixed>|bool|float|int|null|string|\stdClass
     */
    public static function decode(string $json, bool $associative = false, int $depth = 512): mixed
    {
        if ($depth < 1) {
            throw new \ValueError('simdjson_decode(): Argument #3 ($depth) must be greater than 0');
        }
        $decoded = VmJsonFormat::decode($json, $associative, $depth, 0);
        $error = VmJson::lastError();
        if (0 !== $error) {
            throw new SimdJsonException(VmJson::errorMsgForCode($error), $error);
        }

        return $decoded;
    }

    public static function isValid(string $json, int $depth = 512): bool
    {
        if ($depth < 1) {
            throw new \ValueError('simdjson_is_valid(): Argument #2 ($depth) must be greater than 0');
        }
        $result = VmJsonScanner::validate($json, $depth, 0);

        return VmJsonScanner::RESULT_VALID === $result;
    }
}
