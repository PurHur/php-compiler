<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Single source of truth for PHP superglobal identifier names (#5391, #1056).
 *
 * php-src: main/php_variables.c — registered auto-globals; GLOBALS is special-cased in Zend.
 */
final class SuperglobalNames
{
    /** @var list<string> */
    public const ALL = [
        'GLOBALS',
        '_GET',
        '_POST',
        '_SERVER',
        '_REQUEST',
        '_COOKIE',
        '_ENV',
        '_FILES',
        '_SESSION',
    ];

    public static function isSuperglobalName(string $name): bool
    {
        // Compile-time switch for self-host AOT: avoid class-const ALL fetch in JIT (#816).
        switch ($name) {
            case 'GLOBALS':
            case '_GET':
            case '_POST':
            case '_SERVER':
            case '_REQUEST':
            case '_COOKIE':
            case '_ENV':
            case '_FILES':
            case '_SESSION':
                return true;
            default:
                return false;
        }
    }
}
