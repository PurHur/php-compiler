<?php

declare(strict_types=1);

/**
 * Maintainer repro: Sorting / SortDirection withheld on Zend 8.2 reference profile (#12362).
 *
 * php-src: ext/standard/basic_functions.stub.php — PHP 8.4+.
 */

if (class_exists('Sorting', false) || enum_exists('SortDirection', false)) {
    echo "fail: Sorting/SortDirection registered on reference profile\n";
    exit(1);
}

echo "ok\n";
