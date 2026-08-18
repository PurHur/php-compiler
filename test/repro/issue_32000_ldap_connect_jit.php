<?php

declare(strict_types=1);

/**
 * Issue #32000 — ldap_connect() JIT/AOT lowering via LdapDnJitHelper::connectUri.
 *
 * php-src: ext/ldap/ldap.c PHP_FUNCTION(ldap_connect) / ldap_initialize
 *
 * Does not call ldap_bind/ldap_unbind (those remain VM-only until #32001/#32002).
 */
echo 'ldap=', extension_loaded('ldap') ? '1' : '0', PHP_EOL;
echo 'fn=', function_exists('ldap_connect') ? '1' : '0', PHP_EOL;

if (!function_exists('ldap_connect')) {
    echo "ok\n";
    exit(0);
}

$uri = @ldap_connect('ldap://127.0.0.1');
if (false === $uri) {
    echo "uri=false\n";
} elseif ($uri instanceof LDAP\Connection) {
    echo "uri=obj\n";
} else {
    echo "uri=other\n";
}

$hostPort = @ldap_connect('127.0.0.1', 389);
if (false === $hostPort) {
    echo "hostport=false\n";
} elseif ($hostPort instanceof LDAP\Connection) {
    echo "hostport=obj\n";
} else {
    echo "hostport=other\n";
}

$none = @ldap_connect();
if (false === $none) {
    echo "none=false\n";
} elseif ($none instanceof LDAP\Connection) {
    echo "none=obj\n";
} else {
    echo "none=other\n";
}

echo "ok\n";
