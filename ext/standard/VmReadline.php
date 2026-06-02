<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * readline() — interactive CLI line input via host ext/readline (ext/readline/readline.c, #3776).
 */
final class VmReadline
{
    public static function read(?string $prompt): string|false
    {
        if (!\function_exists('readline')) {
            return false;
        }

        $line = null === $prompt ? \readline() : \readline($prompt);

        return false === $line ? false : $line;
    }
}
