<?php

declare(strict_types=1);

/**
 * Maintainer repro: json_validate() on 8.4.0-dev forward profile (#13058).
 *
 * php-src: ext/json/php_json.c — PHP 8.3+.
 */

if (!\function_exists('json_validate')) {
    echo "skip — reference profile withholds json_validate until stable 8.4.0 (#13365)\n";
    exit(0);
}

if (!json_validate('{"a":1}')) {
    echo "fail: valid JSON rejected\n";
    exit(1);
}

if (json_validate('{')) {
    echo "fail: invalid JSON accepted\n";
    exit(1);
}

echo "ok\n";
