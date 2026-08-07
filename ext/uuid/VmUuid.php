<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\ext\standard\VmRandom;
use PHPCompiler\ext\standard\VmString;

/**
 * RFC 4122 UUID generation + introspection (php/pecl-networking-uuid uuid.c; #5910 / #22228).
 *
 * Pure PHP — no host Zend or libuuid delegation.
 */
final class VmUuid
{
    // 100-ns intervals between UUID epoch (1582-10-15) and Unix epoch (1970-01-01).
    private const TIME_OFFSET_INT = 0x01B21DD213814000;

    private const BIN_LEN = 16;

    private static ?string $timeNode = null;

    public static function create(int $uuidType = UuidConstants::UUID_TYPE_DEFAULT): string
    {
        return match ($uuidType) {
            UuidConstants::UUID_TYPE_TIME => self::generateTime(),
            UuidConstants::UUID_TYPE_RANDOM,
            UuidConstants::UUID_TYPE_DCE,
            UuidConstants::UUID_TYPE_DEFAULT => self::generateRandom(),
            default => throw new \ValueError(
                \sprintf("uuid_create(): Unknown/invalid UUID type '%d' requested", $uuidType)
            ),
        };
    }

    /** PECL uuid_is_valid — true when string parses as a UUID. */
    public static function isValid(string $uuid): bool
    {
        return null !== self::tryParse($uuid);
    }

    /**
     * PECL uuid_parse — 16-byte binary uuid_t.
     *
     * @throws \ValueError
     */
    public static function parse(string $uuid): string
    {
        $bin = self::tryParse($uuid);
        if (null === $bin) {
            throw new \ValueError('uuid_parse(): Argument #1 ($uuid) UUID expected');
        }

        return $bin;
    }

    /**
     * PECL uuid_unparse — canonical 8-4-4-4-12 string from 16-byte binary.
     *
     * @throws \ValueError
     */
    public static function unparse(string $bin): string
    {
        if (self::BIN_LEN !== \strlen($bin)) {
            throw new \ValueError('uuid_unparse(): Argument #1 ($uuid) UUID expected');
        }

        return self::formatCanonical($bin);
    }

    /**
     * PECL uuid_compare — memcmp-style -1/0/1.
     *
     * @throws \ValueError
     */
    public static function compare(string $uuid1, string $uuid2): int
    {
        $a = self::tryParse($uuid1);
        if (null === $a) {
            throw new \ValueError('uuid_compare(): Argument #1 ($uuid1) UUID expected');
        }
        $b = self::tryParse($uuid2);
        if (null === $b) {
            throw new \ValueError('uuid_compare(): Argument #2 ($uuid2) UUID expected');
        }
        $cmp = $a <=> $b;
        if ($cmp < 0) {
            return -1;
        }
        if ($cmp > 0) {
            return 1;
        }

        return 0;
    }

    /**
     * PECL uuid_is_null — all-zero UUID.
     *
     * @throws \ValueError
     */
    public static function isNull(string $uuid): bool
    {
        $bin = self::tryParse($uuid);
        if (null === $bin) {
            throw new \ValueError('uuid_is_null(): Argument #1 ($uuid) UUID expected');
        }

        return "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0" === $bin;
    }

    /**
     * PECL uuid_type — RFC version nibble (or UUID_TYPE_NULL for nil).
     *
     * @throws \ValueError
     */
    public static function type(string $uuid): int
    {
        $bin = self::tryParse($uuid);
        if (null === $bin) {
            throw new \ValueError('uuid_type(): Argument #1 ($uuid) UUID expected');
        }
        if ("\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0" === $bin) {
            return UuidConstants::UUID_TYPE_NULL;
        }

        return (\ord($bin[6]) >> 4) & 0x0F;
    }

    /**
     * PECL uuid_variant — libuuid variant classification (NCS/DCE/Microsoft/Other).
     *
     * @throws \ValueError
     */
    public static function variant(string $uuid): int
    {
        $bin = self::tryParse($uuid);
        if (null === $bin) {
            throw new \ValueError('uuid_variant(): Argument #1 ($uuid) UUID expected');
        }
        if ("\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0" === $bin) {
            return UuidConstants::UUID_TYPE_NULL;
        }
        $b = \ord($bin[8]);
        if (0 === ($b & 0x80)) {
            return UuidConstants::UUID_VARIANT_NCS;
        }
        if (0 === ($b & 0x40)) {
            return UuidConstants::UUID_VARIANT_DCE;
        }
        if (0 === ($b & 0x20)) {
            return UuidConstants::UUID_VARIANT_MICROSOFT;
        }

        return UuidConstants::UUID_VARIANT_OTHER;
    }

    /**
     * PECL uuid_time — Unix timestamp from a DCE time (version 1) UUID.
     *
     * @throws \ValueError
     */
    public static function time(string $uuid): int
    {
        $bin = self::requireDceTime($uuid, 'uuid_time');
        $timeLow = (\ord($bin[0]) << 24) | (\ord($bin[1]) << 16) | (\ord($bin[2]) << 8) | \ord($bin[3]);
        $timeMid = (\ord($bin[4]) << 8) | \ord($bin[5]);
        $timeHi = ((\ord($bin[6]) & 0x0F) << 8) | \ord($bin[7]);
        // 60-bit UUID timestamp in 100-ns ticks since 1582-10-15.
        $ticks = ($timeHi << 48) | ($timeMid << 32) | $timeLow;
        $unix = \intdiv($ticks - self::TIME_OFFSET_INT, 10000000);

        return $unix;
    }

    /**
     * PECL uuid_mac — last 12 hex digits (node) of a DCE time UUID.
     *
     * @throws \ValueError
     */
    public static function mac(string $uuid): string
    {
        $bin = self::requireDceTime($uuid, 'uuid_mac');

        return \bin2hex(\substr($bin, 10, 6));
    }

    /**
     * PECL uuid_generate_md5 — RFC 4122 name-based UUID v3 (MD5).
     *
     * @throws \ValueError
     */
    public static function generateMd5(string $uuidNs, string $name): string
    {
        return self::generateNameBased($uuidNs, $name, 'md5', 3, 'uuid_generate_md5');
    }

    /**
     * PECL uuid_generate_sha1 — RFC 4122 name-based UUID v5 (SHA-1).
     *
     * @throws \ValueError
     */
    public static function generateSha1(string $uuidNs, string $name): string
    {
        return self::generateNameBased($uuidNs, $name, 'sha1', 5, 'uuid_generate_sha1');
    }

    /** @return ?string 16-byte binary or null when invalid */
    public static function tryParse(string $uuid): ?string
    {
        $uuid = \strtolower(\trim($uuid));
        if (1 === \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            $hex = \str_replace('-', '', $uuid);
        } elseif (1 === \preg_match('/^[0-9a-f]{32}$/', $uuid)) {
            $hex = $uuid;
        } else {
            return null;
        }
        $bin = \hex2bin($hex);
        if (false === $bin || self::BIN_LEN !== \strlen($bin)) {
            return null;
        }

        return $bin;
    }

    private static function requireDceTime(string $uuid, string $fn): string
    {
        $bin = self::tryParse($uuid);
        if (null === $bin) {
            throw new \ValueError($fn.'(): Argument #1 ($uuid) UUID DCE TIME expected');
        }
        $version = (\ord($bin[6]) >> 4) & 0x0F;
        $variant = self::variantFromBin($bin);
        if (1 !== $version || UuidConstants::UUID_VARIANT_DCE !== $variant) {
            throw new \ValueError($fn.'(): Argument #1 ($uuid) UUID DCE TIME expected');
        }

        return $bin;
    }

    private static function variantFromBin(string $bin): int
    {
        $b = \ord($bin[8]);
        if (0 === ($b & 0x80)) {
            return UuidConstants::UUID_VARIANT_NCS;
        }
        if (0 === ($b & 0x40)) {
            return UuidConstants::UUID_VARIANT_DCE;
        }
        if (0 === ($b & 0x20)) {
            return UuidConstants::UUID_VARIANT_MICROSOFT;
        }

        return UuidConstants::UUID_VARIANT_OTHER;
    }

    private static function formatCanonical(string $bin): string
    {
        $h = \bin2hex($bin);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            \substr($h, 0, 8),
            \substr($h, 8, 4),
            \substr($h, 12, 4),
            \substr($h, 16, 4),
            \substr($h, 20, 12)
        );
    }

    /**
     * libuuid uuid_generate_md5 / uuid_generate_sha1 (php/pecl-networking-uuid uuid.c; #27836).
     *
     * @throws \ValueError
     */
    private static function generateNameBased(
        string $uuidNs,
        string $name,
        string $algo,
        int $version,
        string $fn
    ): string {
        $nsBin = self::tryParse($uuidNs);
        if (null === $nsBin) {
            throw new \ValueError($fn.'(): Argument #1 ($uuid_ns) UUID expected');
        }
        $digest = \hash($algo, $nsBin.$name, true);
        if (false === $digest || \strlen($digest) < self::BIN_LEN) {
            throw new \RuntimeException($fn.'(): hash() failed');
        }
        $bin = \substr($digest, 0, self::BIN_LEN);
        $bin[6] = \chr((\ord($bin[6]) & 0x0F) | (($version & 0x0F) << 4));
        $bin[8] = \chr((\ord($bin[8]) & 0x3F) | 0x80);

        return self::formatCanonical($bin);
    }

    private static function generateRandom(): string
    {
        $uuid = \bin2hex(VmString::randomBytes(16));

        return \sprintf(
            '%08s-%04s-4%03s-%04x-%012s',
            \substr($uuid, 0, 8),
            \substr($uuid, 8, 4),
            \substr($uuid, 13, 3),
            \hexdec(\substr($uuid, 16, 4)) & 0x3FFF | 0x8000,
            \substr($uuid, 20, 12)
        );
    }

    private static function generateTime(): string
    {
        $micro = \microtime(false);
        $time = \substr($micro, 11).\substr($micro, 2, 7);
        $timeHex = \str_pad(\dechex((int) $time + self::TIME_OFFSET_INT), 16, '0', \STR_PAD_LEFT);
        $clockSeq = VmRandom::randomInt(0, 0x3FFF);

        if (null === self::$timeNode) {
            self::$timeNode = \sprintf(
                '%06x%06x',
                VmRandom::randomInt(0, 0xFFFFFF) | 0x010000,
                VmRandom::randomInt(0, 0xFFFFFF)
            );
        }

        return \sprintf(
            '%08s-%04s-1%03s-%04x-%012s',
            \substr($timeHex, -8),
            \substr($timeHex, -12, 4),
            \substr($timeHex, -15, 3),
            $clockSeq | 0x8000,
            self::$timeNode
        );
    }
}
