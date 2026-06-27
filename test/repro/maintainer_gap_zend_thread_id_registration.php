<?php

declare(strict_types=1);

/**
 * Maintainer repro: zend_thread_id() withheld on Zend 8.2 reference profile (#12386).
 *
 * php-src: ext/standard/basic_functions.c — PHP 8.1+.
 */

if (\function_exists('zend_thread_id')) {
    echo "fail: zend_thread_id registered on Zend 8.2 reference profile\n";
    exit(1);
}

echo "ok\n";
