<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: date_parse() offset timezone metadata keys (#14806).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_parse)
 */

$r = date_parse('2024-01-01T12:00:00+02:00');
$zoneType = $r['zone_type'] ?? null;
$zone = $r['zone'] ?? null;
$isDst = $r['is_dst'] ?? null;

echo 'zone_type=', var_export($zoneType, true), "\n";
echo 'zone=', var_export($zone, true), "\n";
echo 'is_dst=', var_export($isDst, true), "\n";

if (1 !== $zoneType || 7200 !== $zone || false !== $isDst) {
    exit(1);
}

$r2 = date_parse('2024-01-01T12:00:00Z');
if (2 !== ($r2['zone_type'] ?? null) || 0 !== ($r2['zone'] ?? null) || false !== ($r2['is_dst'] ?? null)) {
    echo 'utc_fail zone_type='.var_export($r2['zone_type'] ?? null, true)."\n";
    exit(1);
}

echo "ok\n";
