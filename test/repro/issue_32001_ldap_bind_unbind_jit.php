<?php

declare(strict_types=1);

/**
 * Issue #32001 / #32002 — ldap_connect + ldap_bind + ldap_unbind JIT lowering.
 *
 * php-src: ext/ldap/ldap.c
 */
echo 'fn=', function_exists('ldap_connect') ? '1' : '0', PHP_EOL;

if (!function_exists('ldap_connect')) {
    echo "skip\n";
    exit(0);
}

$link = @ldap_connect('ldap://127.0.0.1');
if (!($link instanceof LDAP\Connection)) {
    echo "connect=0\n";
    exit(0);
}

@ldap_bind($link);
echo 'bind=1', PHP_EOL;
echo 'errno=', ldap_errno($link), PHP_EOL;
$closed = ldap_unbind($link);
echo 'unbind=', $closed ? '1' : '0', PHP_EOL;
echo "ok\n";
