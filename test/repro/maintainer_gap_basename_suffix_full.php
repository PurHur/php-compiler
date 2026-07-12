<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: basename() suffix equals trailing name (#18111).
 * php-src: ext/standard/basename.c
 */

$r = basename('/a/dir', 'dir');
if ('dir' !== $r) {
    echo "fail: basename('/a/dir', 'dir') expected 'dir', got ", var_export($r, true), "\n";
    exit(1);
}

$r = basename('/a/b/c.txt', '.txt');
if ('c' !== $r) {
    echo "fail: basename('/a/b/c.txt', '.txt') expected 'c', got ", var_export($r, true), "\n";
    exit(1);
}

echo "ok\n";
