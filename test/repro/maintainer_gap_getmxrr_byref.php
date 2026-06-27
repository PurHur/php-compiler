<?php

declare(strict_types=1);

/**
 * Issue #12704 — getmxrr()/dns_get_mx() MX host list by-ref must not fatal.
 */

$hosts = [];
$ok = getmxrr('example.com', $hosts);
if (!is_bool($ok)) {
    echo "fail: getmxrr return type\n";
    exit(1);
}
if (!is_array($hosts)) {
    echo "fail: hosts not array after getmxrr\n";
    exit(1);
}

$mx = [];
$ok2 = dns_get_mx('example.com', $mx);
if (!is_bool($ok2)) {
    echo "fail: dns_get_mx return type\n";
    exit(1);
}
if (!is_array($mx)) {
    echo "fail: mx not array after dns_get_mx\n";
    exit(1);
}

echo "ok\n";
