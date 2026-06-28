<?php

declare(strict_types=1);

/**
 * Maintainer repro: json_validate() phantom on Zend 8.2 reference profile (#13365).
 *
 * php-src: ext/json/php_json.c — PHP 8.3+.
 */

if (\function_exists('json_validate')) {
    echo "present\n";
    exit(1);
}

echo "absent\n";
