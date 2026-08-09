<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

/**
 * PECL mailparse predefined constants (mailparse.c MINIT; #28064).
 */
final class MailparseConstants
{
    /** Extract to output buffer (MAILPARSE_EXTRACT_OUTPUT). */
    public const EXTRACT_OUTPUT = 0;

    /** Extract to a caller-supplied stream (MAILPARSE_EXTRACT_STREAM). */
    public const EXTRACT_STREAM = 1;

    /** Return extracted data as a string (MAILPARSE_EXTRACT_RETURN). */
    public const EXTRACT_RETURN = 2;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'MAILPARSE_EXTRACT_OUTPUT' => self::EXTRACT_OUTPUT,
            'MAILPARSE_EXTRACT_STREAM' => self::EXTRACT_STREAM,
            'MAILPARSE_EXTRACT_RETURN' => self::EXTRACT_RETURN,
        ];
    }
}
