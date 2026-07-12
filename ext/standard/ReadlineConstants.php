<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * readline extension constants (php-src ext/readline/readline.c; #17799).
 */
final class ReadlineConstants
{
    /** @return array<string, string> */
    public static function registeredConstants(): array
    {
        return [
            'READLINE_LIB' => 'libedit',
        ];
    }
}
