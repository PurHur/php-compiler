<?php

declare(strict_types=1);

/**
 * Maintainer repro: createLazyGhost/createLazyProxy withheld on Zend 8.2 reference profile (#12375).
 *
 * php-src: Zend/zend_lazy_objects.c — PHP 8.4+.
 */

$registered = [];
if (\function_exists('createLazyGhost')) {
    $registered[] = 'createLazyGhost';
}
if (\function_exists('createLazyProxy')) {
    $registered[] = 'createLazyProxy';
}

if ([] !== $registered) {
    echo 'registered: '.implode(',', $registered)."\n";
    exit(1);
}

echo "ok\n";
exit(0);
