<?php

declare(strict_types=1);

/**
 * Issue #32146 — ldap_bind_ext() JIT/AOT lowering.
 *
 * php-src: ext/ldap/ldap.c
 *
 * Standalone AOT has no libldap FFI, so ldap_connect() may fail; the TypeError
 * and bind_ext call still have to lower (not LogicException).
 */
error_reporting(E_ALL);

echo 'fn=', function_exists('ldap_bind_ext') ? '1' : '0', PHP_EOL;

if (!function_exists('ldap_connect')) {
    echo "skip\n";
    echo "ok\n";
    exit(0);
}

$link = @ldap_connect('ldap://127.0.0.1');
if (!($link instanceof LDAP\Connection)) {
    echo "connect=0\n";
    echo "ok\n";
    exit(0);
}

$r = @ldap_bind_ext($link);
echo 'anon=', false === $r ? 'false' : (is_object($r) ? 'object' : 'other'), PHP_EOL;
echo 'errno_set=', ldap_errno($link) !== 0 ? '1' : '0', PHP_EOL;

$r2 = @ldap_bind_ext($link, null, null, []);
echo 'ctrl=', false === $r2 ? 'false' : 'other', PHP_EOL;

try {
    ldap_bind_ext(42);
    echo "bad_conn=uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn=typeerror\n";
}

try {
    ldap_bind_ext($link, "cn=x\0y");
    echo "nul_dn=uncaught\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'null bytes') ? "nul_dn=typeerror\n" : "nul_dn=other\n";
}

echo "ok\n";
