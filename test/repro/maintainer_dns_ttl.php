<?php

declare(strict_types=1);

/**
 * dns_get_record() TTL field parity (#9307).
 *
 * php-src: ext/standard/dns.c — php_dns_make_record add_assoc_long(..., "ttl", ...)
 */

$r = dns_get_record('php.net', DNS_A);
if (!\is_array($r) || [] === $r) {
    fwrite(STDERR, "skip: no DNS_A records for php.net\n");
    exit(0);
}

$ttl = $r[0]['ttl'] ?? null;
if (!\is_int($ttl) || $ttl <= 0) {
    fwrite(STDERR, 'fail: expected positive ttl, got '.var_export($ttl, true)."\n");
    exit(1);
}

echo "ok ttl=$ttl\n";
