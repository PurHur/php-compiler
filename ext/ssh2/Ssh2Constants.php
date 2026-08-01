<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

/**
 * PECL ssh2 fingerprint / stream constants (php_ssh2.h; #26575).
 */
final class Ssh2Constants
{
    public const FINGERPRINT_MD5 = 0x0000;

    public const FINGERPRINT_SHA1 = 0x0001;

    public const FINGERPRINT_HEX = 0x0000;

    public const FINGERPRINT_RAW = 0x0002;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'SSH2_FINGERPRINT_MD5' => self::FINGERPRINT_MD5,
            'SSH2_FINGERPRINT_SHA1' => self::FINGERPRINT_SHA1,
            'SSH2_FINGERPRINT_HEX' => self::FINGERPRINT_HEX,
            'SSH2_FINGERPRINT_RAW' => self::FINGERPRINT_RAW,
        ];
    }
}
