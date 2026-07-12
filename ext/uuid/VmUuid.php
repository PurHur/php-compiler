<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\ext\standard\VmRandom;
use PHPCompiler\ext\standard\VmString;

/**
 * RFC 4122 UUID generation (php/pecl-networking-uuid uuid.c; issue #5910).
 *
 * Pure PHP — no host Zend or libuuid delegation.
 */
final class VmUuid
{
    // 100-ns intervals between UUID epoch (1582-10-15) and Unix epoch (1970-01-01).
    private const TIME_OFFSET_INT = 0x01B21DD213814000;

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
