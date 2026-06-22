<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for unserialize() runtime (#9163, php-in-PHP).
 *
 * SSOT: {@see VmUnserializeFormat} (php-src ext/standard/var_unserializer.c).
 */
final class UnserializeJitHelper
{
    /**
     * @return array<mixed>|bool|float|int|null|string
     */
    public static function decode(string $payload): mixed
    {
        $decoded = VmUnserializeFormat::decodePayload($payload);
        if (false === $decoded) {
            return false;
        }

        return $decoded;
    }

    /**
     * Session wire decode: array payload or empty array on failure (#6086).
     *
     * @return array<string, mixed>
     */
    public static function decodeSession(string $payload): array
    {
        $decoded = VmUnserializeFormat::decodePayload($payload);
        if (!\is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
