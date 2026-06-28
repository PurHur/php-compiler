<?php
declare(strict_types=1);

/**
 * Maintainer repro: ReflectionConstant withheld on Zend 8.2 reference profile (#12385).
 *
 * php-src: ext/reflection/php_reflection.c — PHP 8.3+.
 */

if (class_exists('ReflectionConstant', false)) {
    echo "skip — forward profile advertises ReflectionConstant (#12385)\n";
    exit(0);
}

echo "ok\n";
