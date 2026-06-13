<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: basename()/dirname() TypeError for wrong operand types (#4715).
 *
 * php-src: ext/standard/basename.c, ext/standard/file.c
 */

try {
    basename('/path', []);
    echo "basename_suffix: uncaught\n";
} catch (Throwable $e) {
    echo 'basename_suffix: ', $e::class, ': ', $e->getMessage(), "\n";
}

try {
    dirname('/a/b/c', []);
    echo "dirname_levels: uncaught\n";
} catch (Throwable $e) {
    echo 'dirname_levels: ', $e::class, ': ', $e->getMessage(), "\n";
}
