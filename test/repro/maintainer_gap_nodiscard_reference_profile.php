<?php

declare(strict_types=1);

/**
 * Maintainer repro: NoDiscard absent on Zend 8.2 reference profile (#13159).
 *
 * php-src: Zend/zend_attributes.c — PHP 8.4+.
 */

if (\class_exists('NoDiscard', false)) {
    echo "skip — forward profile advertises NoDiscard (#13159)\n";
    exit(0);
}

echo "ok\n";
