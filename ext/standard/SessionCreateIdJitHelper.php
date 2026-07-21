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
        if (null !== $prefix && '' !== $prefix) {
            if (\strlen($prefix) > VmSession::MAX_ID_LEN) {
                throw new \ValueError(
                    'session_create_id(): Argument #1 ($prefix) cannot be longer than '
                    .VmSession::MAX_ID_LEN.' characters'
                );
            }
            if ($prefix !== SessionFileStorage::sanitizeId($prefix)) {
                return null;
            }
        }
        $generated = self::generateId();
        if (null === $prefix || '' === $prefix) {
            return $generated;
        }

        return $prefix.$generated;
    }

    private static function generateId(): string
    {
        return self::binToReadable(
            \random_bytes(self::SID_LENGTH),
            self::SID_LENGTH,
            self::SID_BITS_PER_CHAR
        );
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
