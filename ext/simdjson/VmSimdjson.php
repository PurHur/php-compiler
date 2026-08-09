<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simdjson;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmJsonFormat;
use PHPCompiler\ext\standard\VmJsonScanner;

/**
 * PECL simdjson decode/is_valid/key_* — reuse in-tree JSON semantics (#22530, #27857).
 *
 * php-src/PECL ref: JakubOnderka/simdjson_php (RFC 6901 pointers; optional leading `/`).
 * Correctness-first: {@see VmJsonFormat} / {@see VmJsonScanner}, not the simdjson C library.
 */
final class VmSimdjson
{
    /**
     * @return array<mixed>|bool|float|int|null|string|\stdClass
     */
    public static function decode(string $json, bool $associative = false, int $depth = 512): mixed
    {
        self::assertDepth($depth, 'simdjson_decode', 3);
        $decoded = VmJsonFormat::decode($json, $associative, $depth, 0);
        $error = VmJson::lastError();
        if (0 !== $error) {
            throw new SimdJsonException(VmJson::errorMsgForCode($error), $error);
        }

        return $decoded;
    }

    public static function isValid(string $json, int $depth = 512): bool
    {
        self::assertDepth($depth, 'simdjson_is_valid', 2);
        $result = VmJsonScanner::validate($json, $depth, 0);

        return VmJsonScanner::RESULT_VALID === $result;
    }

    public static function keyExists(string $json, string $key, int $depth = 512): bool
    {
        self::assertDepth($depth, 'simdjson_key_exists', 3);
        try {
            self::pointerGet(self::decodeRoot($json, $depth), $key);

            return true;
        } catch (SimdJsonPointerMissing) {
            return false;
        }
    }

    /**
     * @return array<mixed>|bool|float|int|null|string|\stdClass
     */
    public static function keyValue(
        string $json,
        string $key,
        bool $associative = false,
        int $depth = 512
    ): mixed {
        self::assertDepth($depth, 'simdjson_key_value', 4);
        $root = self::decodeRoot($json, $depth, $associative);
        try {
            return self::pointerGet($root, $key);
        } catch (SimdJsonPointerMissing $e) {
            unset($e);
            throw new SimdJsonException('The JSON field referenced does not exist in this object.', 0);
        }
    }

    public static function keyCount(
        string $json,
        string $key,
        int $depth = 512,
        bool $throwIfUncountable = false
    ): int {
        self::assertDepth($depth, 'simdjson_key_count', 3);
        try {
            $value = self::pointerGet(self::decodeRoot($json, $depth, true), $key);
        } catch (SimdJsonPointerMissing $e) {
            unset($e);
            throw new SimdJsonException('The JSON field referenced does not exist in this object.', 0);
        }
        if (\is_array($value)) {
            return \count($value);
        }
        if (\is_object($value)) {
            return \count(get_object_vars($value));
        }
        if ($throwIfUncountable) {
            throw new SimdJsonException('JSON pointer refers to a value that cannot be counted', 0);
        }

        return 0;
    }

    private static function assertDepth(int $depth, string $function, int $argNum): void
    {
        if ($depth < 1) {
            throw new SimdJsonValueError(sprintf(
                '%s(): Argument #%d ($depth) must be greater than zero',
                $function,
                $argNum
            ));
        }
    }

    /**
     * @return array<mixed>|bool|float|int|null|string|\stdClass
     */
    private static function decodeRoot(string $json, int $depth, bool $associative = true): mixed
    {
        $decoded = VmJsonFormat::decode($json, $associative, $depth, 0);
        $error = VmJson::lastError();
        if (0 !== $error) {
            throw new SimdJsonException(VmJson::errorMsgForCode($error), $error);
        }

        return $decoded;
    }

    /**
     * RFC 6901 JSON pointer; PECL prepends `/` when the pointer does not start with `/`.
     *
     * @param array<mixed>|bool|float|int|null|string|\stdClass $root
     * @return array<mixed>|bool|float|int|null|string|\stdClass
     */
    private static function pointerGet(mixed $root, string $pointer): mixed
    {
        if ('' !== $pointer && '/' !== $pointer[0]) {
            $pointer = '/'.$pointer;
        }
        if ('' === $pointer || '/' === $pointer) {
            return $root;
        }
        $parts = explode('/', substr($pointer, 1));
        $cur = $root;
        foreach ($parts as $raw) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $raw);
            if (\is_array($cur)) {
                if (array_is_list($cur)) {
                    if (!preg_match('/^(0|[1-9][0-9]*)$/', $token)) {
                        throw new SimdJsonPointerMissing();
                    }
                    $idx = (int) $token;
                    if (!array_key_exists($idx, $cur)) {
                        throw new SimdJsonPointerMissing();
                    }
                    $cur = $cur[$idx];
                } else {
                    if (!array_key_exists($token, $cur)) {
                        throw new SimdJsonPointerMissing();
                    }
                    $cur = $cur[$token];
                }
            } elseif (\is_object($cur)) {
                $vars = get_object_vars($cur);
                if (!array_key_exists($token, $vars)) {
                    throw new SimdJsonPointerMissing();
                }
                $cur = $vars[$token];
            } else {
                throw new SimdJsonPointerMissing();
            }
        }

        return $cur;
    }
}

/** Internal: JSON pointer path missing (maps to PECL NO_SUCH_FIELD / false for key_exists). */
final class SimdJsonPointerMissing extends \Exception
{
}
