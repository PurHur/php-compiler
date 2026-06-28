<?php

declare(strict_types=1);

/**
 * Maintainer repro: NoDiscard on 8.4.0-dev forward profile (#13159).
 *
 * php-src: Zend/zend_attributes.c — PHP 8.4+.
 */

if (!\class_exists('NoDiscard', false)) {
    echo "fail: NoDiscard class missing on forward profile\n";
    exit(1);
}

if (!(new \ReflectionClass('NoDiscard'))->isInternal()) {
    echo "fail: NoDiscard is not internal\n";
    exit(1);
}

echo "ok\n";
