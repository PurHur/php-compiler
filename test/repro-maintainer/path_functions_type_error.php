<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: basename()/dirname() TypeError for wrong operand types (#4715).
 *
 * php-src: ext/standard/basename.c, ext/standard/file.c
 */

$tests = [
    'basename_suffix' => static function (): void {
        basename('/path', []);
    },
    'dirname_levels' => static function (): void {
        dirname('/a/b/c', []);
    },
];
foreach ($tests as $label => $fn) {
    try {
        $fn();
        echo $label, ": uncaught\n";
    } catch (Throwable $e) {
        echo $label, ': ', $e::class, ': ', $e->getMessage(), "\n";
    }
}
