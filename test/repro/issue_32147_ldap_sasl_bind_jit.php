<?php

declare(strict_types=1);

/**
 * Issue #32147 — ldap_sasl_bind() JIT lowering.
 *
 * php-src: ext/ldap/ldap.c PHP_FUNCTION(ldap_sasl_bind)
 */
error_reporting(E_ALL);

echo 'fn=', function_exists('ldap_sasl_bind') ? '1' : '0', PHP_EOL;

if (!function_exists('ldap_connect')) {
    echo "skip\n";
    exit(0);
}

$link = @ldap_connect('ldap://127.0.0.1');
if (!($link instanceof LDAP\Connection)) {
    echo "connect=0\n";
    exit(0);
}

$ok = @ldap_sasl_bind($link);
echo 'sasl=', false === $ok ? 'fail' : 'ok', PHP_EOL;
echo 'errno_set=', ldap_errno($link) !== 0 ? '1' : '0', PHP_EOL;

$r = @ldap_sasl_bind($link, null, null, 'INVALID');
echo false === $r ? "invalid_mech_false\n" : "invalid_mech_other\n";

try {
    ldap_sasl_bind(42);
    echo "bad_conn=uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn=typeerror\n";
}

echo "ok\n";
