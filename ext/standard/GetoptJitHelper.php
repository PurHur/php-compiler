<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for getopt() (#3251 phase 2).
 *
 * php-src: ext/standard/php_getopt.c — PHP_FUNCTION(getopt)
 */
final class GetoptJitHelper
{
    /**
     * @param list<string> $argv
     * @param list<string> $longOptions
     *
     * @return array<string, bool|string|list<bool|string>>|false
     */
    public static function parse(string $shortOptions, array $longOptions, array $argv): array|false
    {
        return GetoptEngine::parse($argv, $shortOptions, $longOptions);
    }
}
