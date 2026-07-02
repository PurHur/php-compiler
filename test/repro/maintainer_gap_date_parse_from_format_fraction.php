<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: date_parse_from_format() fraction false without u token (#14808).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_parse_from_format)
 */

$r = date_parse_from_format('Y-m-d', '2024-01-01');
$fraction = $r['fraction'] ?? null;
echo 'fraction=', var_export($fraction, true), "\n";
echo 'identical_ok=', (false === $fraction ? 'yes' : 'no'), "\n";

$r2 = date_parse_from_format('Y-m-d H:i:s.u', '2024-01-01 12:00:00.123456');
$fraction2 = $r2['fraction'] ?? null;
echo 'with_u=', var_export($fraction2, true), "\n";
echo 'with_u_float=', var_export(\is_float($fraction2), true), "\n";

if (false !== $fraction || !\is_float($fraction2)) {
    exit(1);
}

echo "ok\n";
