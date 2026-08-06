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

    /** PHP_SSH2_TERM_UNIT_CHARS (php_ssh2.h; #26663). */
    public const TERM_UNIT_CHARS = 0;

    /** PHP_SSH2_TERM_UNIT_PIXELS (php_ssh2.h; #26663). */
    public const TERM_UNIT_PIXELS = 1;

    /** LIBSSH2_POLLFD_* / PECL SSH2_POLL* (#26735). */
    public const POLLIN = 0x0001;

    public const POLLEXT = 0x0002;

    public const POLLOUT = 0x0004;

    public const POLLERR = 0x0008;

    public const POLLHUP = 0x0010;

    public const POLLNVAL = 0x0020;

    public const POLL_SESSION_CLOSED = 0x0010;

    public const POLL_CHANNEL_CLOSED = 0x0080;

    public const POLL_LISTENER_CLOSED = 0x0080;

    /** PHP_SSH2_DEFAULT_POLL_TIMEOUT (seconds). */
    public const DEFAULT_POLL_TIMEOUT = 30;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'SSH2_FINGERPRINT_MD5' => self::FINGERPRINT_MD5,
            'SSH2_FINGERPRINT_SHA1' => self::FINGERPRINT_SHA1,
            'SSH2_FINGERPRINT_HEX' => self::FINGERPRINT_HEX,
            'SSH2_FINGERPRINT_RAW' => self::FINGERPRINT_RAW,
            'SSH2_TERM_UNIT_CHARS' => self::TERM_UNIT_CHARS,
            'SSH2_TERM_UNIT_PIXELS' => self::TERM_UNIT_PIXELS,
            'SSH2_POLLIN' => self::POLLIN,
            'SSH2_POLLEXT' => self::POLLEXT,
            'SSH2_POLLOUT' => self::POLLOUT,
            'SSH2_POLLERR' => self::POLLERR,
            'SSH2_POLLHUP' => self::POLLHUP,
            'SSH2_POLLNVAL' => self::POLLNVAL,
            'SSH2_POLL_SESSION_CLOSED' => self::POLL_SESSION_CLOSED,
            'SSH2_POLL_CHANNEL_CLOSED' => self::POLL_CHANNEL_CLOSED,
            'SSH2_POLL_LISTENER_CLOSED' => self::POLL_LISTENER_CLOSED,
        ];
    }
}
