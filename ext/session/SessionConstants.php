<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

/**
 * PHP_SESSION_* status ids (php-src ext/session/php_session.h; issue #6004).
 */
final class SessionConstants
{
    public const PHP_SESSION_DISABLED = 0;

    public const PHP_SESSION_NONE = 1;

    public const PHP_SESSION_ACTIVE = 2;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'PHP_SESSION_DISABLED' => self::PHP_SESSION_DISABLED,
            'PHP_SESSION_NONE' => self::PHP_SESSION_NONE,
            'PHP_SESSION_ACTIVE' => self::PHP_SESSION_ACTIVE,
        ];
    }
}
