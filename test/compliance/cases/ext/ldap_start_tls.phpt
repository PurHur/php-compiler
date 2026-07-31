--TEST--
stdlib ldap_start_tls registration (#21852, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_ENABLE_LDAP=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo function_exists('ldap_start_tls') ? "registered\n" : "missing\n";

$link = ldap_connect('ldap://127.0.0.1');
$ok = @ldap_start_tls($link);
echo false === $ok ? "tls_fail\n" : "tls_ok\n";
echo ldap_errno($link) !== 0 ? "errno_set\n" : "errno_zero\n";

try {
    ldap_start_tls(42);
    echo "bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn_typeerror\n";
}

ldap_unbind($link);
?>
--EXPECT--
registered
tls_fail
errno_set
bad_conn_typeerror
