<?php

declare(strict_types=1);

/**
 * Issue #32109 — ldap_start_tls() JIT lowering.
 *
 * php-src: ext/ldap/ldap.c
 */
error_reporting(E_ALL);

echo 'fn=', function_exists('ldap_start_tls') ? '1' : '0', PHP_EOL;

if (!function_exists('ldap_connect')) {
    echo "skip\n";
    exit(0);
}

$link = @ldap_connect('ldap://127.0.0.1');
if (!($link instanceof LDAP\Connection)) {
    echo "connect=0\n";
    exit(0);
}

$ok = @ldap_start_tls($link);
echo 'tls=', false === $ok ? 'fail' : 'ok', PHP_EOL;
echo 'errno_set=', ldap_errno($link) !== 0 ? '1' : '0', PHP_EOL;

try {
    ldap_start_tls(42);
    echo "bad_conn=uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn=typeerror\n";
}

echo "ok\n";
