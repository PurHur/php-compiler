<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

/**
 * ext/mailparse advertisement (PECL mailparse / mailparse.c; #6383).
 *
 * Phase 1 always advertises builtins so function_exists('mailparse_msg_create') is true
 * without a host PECL mailparse install (pure PHP parser).
 */
final class MailparseExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }
}
