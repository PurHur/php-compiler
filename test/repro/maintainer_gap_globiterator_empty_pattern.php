<?php
// GlobIterator(''): Zend ValueError cites Argument #1 ($pattern) (php-src spl_directory.c).
error_reporting(E_ALL);
try {
    $g = new GlobIterator('');
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
