<?php

declare(strict_types=1);

/**
 * Issue #32148 — ldap_set_rebind_proc() JIT lowering (null-clear + invalid handle).
 *
 * php-src: ext/ldap/ldap.c PHP_FUNCTION(ldap_set_rebind_proc)
 */
putenv('PHP_COMPILER_ENABLE_LDAP=1');
$_ENV['PHP_COMPILER_ENABLE_LDAP'] = '1';
$_SERVER['PHP_COMPILER_ENABLE_LDAP'] = '1';

error_reporting(E_ALL);

echo 'fn=', function_exists('ldap_set_rebind_proc') ? '1' : '0', PHP_EOL;

if (!function_exists('ldap_connect')) {
    echo "skip\n";
    exit(0);
}

$link = @ldap_connect('ldap://127.0.0.1');
if (!($link instanceof LDAP\Connection)) {
    echo "connect=0\n";
    exit(0);
}

try {
    ldap_set_rebind_proc(42, null);
    echo "bad_conn=uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn=typeerror\n";
}

$ok = ldap_set_rebind_proc($link, null);
echo 'clear=', $ok ? '1' : '0', PHP_EOL;

try {
    ldap_set_rebind_proc($link, 42);
    echo "bad_cb=uncaught\n";
} catch (TypeError $e) {
    echo "bad_cb=typeerror\n";
}

ldap_unbind($link);
echo "ok\n";
