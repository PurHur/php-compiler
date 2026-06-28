<?php

declare(strict_types=1);

/**
 * Maintainer repro: Override builtin class withheld on Zend 8.2 reference profile (#12387).
 *
 * php-src: Zend/zend_attributes.c — PHP 8.3+.
 */

if (class_exists('Override', false)) {
    echo "registered\n";
    exit(1);
}

echo "missing\n";
exit(0);
