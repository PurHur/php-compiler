<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

/**
 * PECL gnupg predefined constants (gnupg.c MINIT / gpgme.h; #28064).
 *
 * Integer values match GPGME enums; ERROR_* are PECL-local (not gpgme).
 */
final class GnupgConstants
{
    public const SIG_MODE_NORMAL = 0;

    public const SIG_MODE_DETACH = 1;

    public const SIG_MODE_CLEAR = 2;

    public const VALIDITY_UNKNOWN = 0;

    public const VALIDITY_UNDEFINED = 1;

    public const VALIDITY_NEVER = 2;

    public const VALIDITY_MARGINAL = 3;

    public const VALIDITY_FULL = 4;

    public const VALIDITY_ULTIMATE = 5;

    public const PROTOCOL_OpenPGP = 0;

    public const PROTOCOL_CMS = 1;

    public const SIGSUM_VALID = 0x0001;

    public const SIGSUM_GREEN = 0x0002;

    public const SIGSUM_RED = 0x0004;

    public const SIGSUM_KEY_REVOKED = 0x0010;

    public const SIGSUM_KEY_EXPIRED = 0x0020;

    public const SIGSUM_SIG_EXPIRED = 0x0040;

    public const SIGSUM_KEY_MISSING = 0x0080;

    public const SIGSUM_CRL_MISSING = 0x0100;

    public const SIGSUM_CRL_TOO_OLD = 0x0200;

    public const SIGSUM_BAD_POLICY = 0x0400;

    public const SIGSUM_SYS_ERROR = 0x0800;

    public const ERROR_WARNING = 1;

    public const ERROR_EXCEPTION = 2;

    public const ERROR_SILENT = 3;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'GNUPG_SIG_MODE_NORMAL' => self::SIG_MODE_NORMAL,
            'GNUPG_SIG_MODE_DETACH' => self::SIG_MODE_DETACH,
            'GNUPG_SIG_MODE_CLEAR' => self::SIG_MODE_CLEAR,
            'GNUPG_VALIDITY_UNKNOWN' => self::VALIDITY_UNKNOWN,
            'GNUPG_VALIDITY_UNDEFINED' => self::VALIDITY_UNDEFINED,
            'GNUPG_VALIDITY_NEVER' => self::VALIDITY_NEVER,
            'GNUPG_VALIDITY_MARGINAL' => self::VALIDITY_MARGINAL,
            'GNUPG_VALIDITY_FULL' => self::VALIDITY_FULL,
            'GNUPG_VALIDITY_ULTIMATE' => self::VALIDITY_ULTIMATE,
            'GNUPG_PROTOCOL_OpenPGP' => self::PROTOCOL_OpenPGP,
            'GNUPG_PROTOCOL_CMS' => self::PROTOCOL_CMS,
            'GNUPG_SIGSUM_VALID' => self::SIGSUM_VALID,
            'GNUPG_SIGSUM_GREEN' => self::SIGSUM_GREEN,
            'GNUPG_SIGSUM_RED' => self::SIGSUM_RED,
            'GNUPG_SIGSUM_KEY_REVOKED' => self::SIGSUM_KEY_REVOKED,
            'GNUPG_SIGSUM_KEY_EXPIRED' => self::SIGSUM_KEY_EXPIRED,
            'GNUPG_SIGSUM_SIG_EXPIRED' => self::SIGSUM_SIG_EXPIRED,
            'GNUPG_SIGSUM_KEY_MISSING' => self::SIGSUM_KEY_MISSING,
            'GNUPG_SIGSUM_CRL_MISSING' => self::SIGSUM_CRL_MISSING,
            'GNUPG_SIGSUM_CRL_TOO_OLD' => self::SIGSUM_CRL_TOO_OLD,
            'GNUPG_SIGSUM_BAD_POLICY' => self::SIGSUM_BAD_POLICY,
            'GNUPG_SIGSUM_SYS_ERROR' => self::SIGSUM_SYS_ERROR,
            'GNUPG_ERROR_WARNING' => self::ERROR_WARNING,
            'GNUPG_ERROR_EXCEPTION' => self::ERROR_EXCEPTION,
            'GNUPG_ERROR_SILENT' => self::ERROR_SILENT,
        ];
    }
}
