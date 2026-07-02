<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: procedural datetime helpers accept float timestamps (#14807).
 *
 * php-src: ext/date/php_date.c — php_date() / php_idate() timestamp coercion
 */

function probe(string $label, callable $fn): void
{
    try {
        $r = $fn();
        echo "$label: ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$label: ", get_class($e), ': ', $e->getMessage(), "\n";
        exit(1);
    }
}

probe('gmdate_u', static fn () => gmdate('u', 1.23456789));
probe('getdate_seconds', static fn () => getdate(1.5)['seconds']);
probe('date_Y', static fn () => date('Y', 1.5));
probe('idate_s', static fn () => idate('s', 1.5));

echo "ok\n";
