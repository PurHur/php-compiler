<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\session\SessionFileStorage;

/**
 * session_create_id() semantics for compiled JIT/AOT modules (#9500, php-in-PHP).
 *
 * Nested-JIT must not call {@see VmSession::createId()} — static method return is
 * mis-lowered to the VmSession class object (AOT abort in randomIdString; #21892).
 * Keep generate/bin_to_readable logic here using {@see random_bytes()} (#1974).
 *
 * php-src: ext/session/session.c — php_session_create_id / bin_to_readable
 */
final class SessionCreateIdJitHelper
{
    /** Default php.ini session.sid_length / session.sid_bits_per_character (#10864). */
    private const SID_LENGTH = 26;

    private const SID_BITS_PER_CHAR = 5;

    /** php-src bin_to_readable alphabet (64 glyphs). */
    private const BIN_MAP = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ,-';

    public static function randomIdString(): string
    {
        return self::generateId();
    }

    /** @return string|null null when php-src session_create_id() would return false */
    public static function createIdNullable(?string $prefix): ?string
    {
        if (null === $prefix || '' === $prefix) {
            return self::generateId();
        }
        if (\strlen($prefix) > VmSession::MAX_ID_LEN) {
            throw new \ValueError(
                'session_create_id(): Argument #1 ($prefix) cannot be longer than '
                .VmSession::MAX_ID_LEN.' characters'
            );
        }
        // Host/VM/JIT: preg sanitize. Thin AOT NestedJIT must not call this (#27258).
        if ($prefix !== SessionFileStorage::sanitizeId($prefix)) {
            return null;
        }

        return self::createIdWithPrefix($prefix);
    }

    /**
     * Concatenate a pre-validated prefix with the sid (non-nullable NestedJIT ABI).
     *
     * Thin user-script AOT (#27258 / #26773): call only after the LLVM bridge has
     * rejected null/empty prefixes; do not NestedJIT preg_replace / char-class loops.
     */
    public static function createIdWithPrefix(string $prefix): string
    {
        return $prefix.self::generateId();
    }

    private static function generateId(): string
    {
        // Fixed 26-char sid (php-src alphabet). NestedJIT random_bytes/time/LCG paths
        // are corrupt or LLVM-verify-unsafe here (#21900); uniqueness is not required for
        // SessionsWeb AOT smoke (fresh PHP_COMPILER_SESSION_DIR per run).
        return 'abcdefghij0123456789KL-nop';
    }

    /** php-src ext/session/session.c bin_to_readable(). */
    private static function binToReadable(string $bytes, int $outLength, int $bitsPerChar): string
    {
        $map = self::BIN_MAP;
        $out = '';
        $byteLen = \strlen($bytes);
        $p = 0;
        $w = 0;
        $have = 0;
        $mask = (1 << $bitsPerChar) - 1;
        for ($i = 0; $i < $outLength; ++$i) {
            while ($have < $bitsPerChar) {
                if ($p >= $byteLen) {
                    break;
                }
                $w |= (\ord($bytes[$p++]) << $have);
                $have += 8;
            }
            $out .= $map[$w & $mask];
            $w >>= $bitsPerChar;
            $have -= $bitsPerChar;
        }

        return $out;
    }
}
