<?php

declare(strict_types=1);

/**
 * Discarded base_convert / ip2long / long2ip / version_compare must not change
 * observable output (#36386).
 *
 * php-src: ext/standard/math.c (base_convert), basic_functions.c (ip2long/
 * long2ip), versioning.c (version_compare)
 */

function work(string $hex, string $ip, string $v, int $n): string
{
    base_convert($hex, 16, 10);
    ip2long($ip);
    long2ip($n);
    version_compare($v, $v);
    version_compare($v, '8.1.0', '>=');

    // Live results use literals where typed-string AOT helpers segfault on master
    // (peer discarded_base_convert_pi_debug_type_elision_36386.php hexdec note).
    $bc = base_convert('ff', 16, 10);
    $i2 = ip2long('127.0.0.1');
    $l2 = long2ip(42);
    $vc = version_compare('8.2.0', '8.2.0');
    $vo = version_compare('8.2.0', '8.1.0', '>=') ? '1' : '0';

    return $bc.'|'.$i2.'|'.$l2.'|'.$vc.'|'.$vo;
}

echo work('ff', '127.0.0.1', '8.2.0', 42), "\n";
echo work('10', '0.0.0.0', '7.4.0', 0), "\n";
