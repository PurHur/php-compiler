<?php

declare(strict_types=1);

/**
 * Issue #32172 — ldap_count_entries() JIT/AOT lowering.
 *
 * php-src: ext/ldap/ldap.c
 *
 * Standalone AOT has no libldap FFI, so ldap_connect() may fail; the
 * ldap_count_entries() call still has to lower (not LogicException).
 */
error_reporting(E_ALL);

echo 'fn=', function_exists('ldap_count_entries') ? '1' : '0', PHP_EOL;

if (!function_exists('ldap_connect') || !function_exists('ldap_count_entries')) {
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

try {
    ldap_count_entries(42, 43);
    echo "bad_conn=uncaught\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'LDAP\\Connection') ? "bad_conn=typeerror\n" : "bad_conn=other\n";
}

try {
    ldap_count_entries($link, 43);
    echo "bad_result=uncaught\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'LDAP\\Result') ? "bad_result=typeerror\n" : "bad_result=other\n";
}

$r = @ldap_bind_ext($link);
if ($r instanceof LDAP\Result) {
    $n = @ldap_count_entries($link, $r);
    echo 'count=', is_int($n) ? 'int' : 'other', PHP_EOL;
} else {
    echo "bind_ext=false\n";
}

echo "ok\n";
