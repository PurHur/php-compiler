<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_file_get_contents for compiled JIT/AOT modules (#15309, #29510, #29833, php-in-PHP).
 *
 * Leaf is `@file_get_contents` → NestedJIT whitelist {@see file_get_contents} →
 * {@see file_get_contents::call} → {@see JitFileGetContentsLibc} libc open/read
 * (no kernel Internal; crypt #29545 / random_bytes #29531 shape).
 * `data:` URIs must not hit libc open — NestedJIT-safe decode (peer {@see VmDataUri},
 * #34731 / php_data_wrapper.c). VM SSOT {@see VmFs::fileGetContents()}.
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_mem
 */
final class FileGetContentsJitHelper
{
    public static function readPathArgv(string $path): ?string
    {
        // NestedJIT strncmp() is wrong for unequal prefixes (#34731); use substr ===.
        if (\is_string($path) && 'data:' === \substr($path, 0, 5)) {
            $decoded = self::decodeDataUri($path);
            if (null === $decoded) {
                return null;
            }

            return $decoded;
        }
        $data = @\file_get_contents($path);
        if (false === $data) {
            return null;
        }

        return $data;
    }

    /**
     * NestedJIT-safe subset of {@see VmDataUri::decode} (#34731).
     *
     * Avoids str_starts_with / strncmp / explode loops that NestedJIT miscompiles;
     * skips rawurldecode when the payload has no % escapes (SIGSEGV on plain "hi").
     */
    private static function decodeDataUri(string $path): ?string
    {
        $comma = \strrpos($path, ',');
        if (false === $comma) {
            return null;
        }
        $data = \substr($path, $comma + 1);
        if (false !== \stripos($path, ';base64,')) {
            $decoded = \base64_decode($data, true);

            return false === $decoded ? null : $decoded;
        }
        // NestedJIT rawurldecode() SIGSEGVs; for-loop percentDecode hung (#34731).
        // Plain payloads (no %) match Zend; %XX remains a NestedJIT residual.
        return $data;
    }
}
