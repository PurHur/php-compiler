<?php

declare(strict_types=1);

/**
 * Maintainer repro: json_validate() withheld on Zend 8.2 reference profile (#12363).
 *
 * php-src: ext/json/php_json.c — PHP 8.3+.
 */

if (\function_exists('json_validate')) {
    echo "fail: json_validate registered on Zend 8.2 reference profile\n";
    exit(1);
}

echo "ok\n";
