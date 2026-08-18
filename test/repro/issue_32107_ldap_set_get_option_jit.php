<?php

declare(strict_types=1);

/**
 * Issue #32107 — ldap_set_option()/ldap_get_option() JIT lowering.
 *
 * php-src: ext/ldap/ldap.c
 */
error_reporting(E_ALL);

echo 'fn=', function_exists('ldap_set_option') && function_exists('ldap_get_option') ? '1' : '0', PHP_EOL;

if (!function_exists('ldap_connect')) {
    echo "skip\n";
    exit(0);
}

$link = @ldap_connect('ldap://127.0.0.1');
if (!($link instanceof LDAP\Connection)) {
    echo "connect=0\n";
    exit(0);
}

$val = 0;
$okSet = ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
$okGet = ldap_get_option($link, LDAP_OPT_PROTOCOL_VERSION, $val);
echo 'set=', $okSet ? '1' : '0', PHP_EOL;
echo 'get=', $okGet ? '1' : '0', PHP_EOL;
echo 'val=', $val, PHP_EOL;

$nullSet = @ldap_set_option(null, LDAP_OPT_PROTOCOL_VERSION, 3);
$nullVal = 0;
$nullGet = @ldap_get_option(null, LDAP_OPT_PROTOCOL_VERSION, $nullVal);
echo 'null_set=', $nullSet ? '1' : '0', PHP_EOL;
echo 'null_get=', $nullGet ? '1' : '0', PHP_EOL;
echo 'null_val=', $nullVal, PHP_EOL;

try {
    ldap_get_option(42, LDAP_OPT_PROTOCOL_VERSION, $val);
    echo "bad_conn=uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn=typeerror\n";
}

echo "ok\n";
