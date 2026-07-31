--TEST--
stdlib ldap_sasl_bind registration + guards (#22176, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_ENABLE_LDAP=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo function_exists('ldap_sasl_bind') ? "sasl_bind=1\n" : "sasl_bind=0\n";

$link = ldap_connect('ldap://127.0.0.1');

try {
    ldap_sasl_bind(42);
    echo "bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn_typeerror\n";
}

$r = @ldap_sasl_bind($link, null, null, 'INVALID');
echo false === $r ? "invalid_mech_false\n" : "invalid_mech_other\n";

ldap_unbind($link);
?>
--EXPECT--
sasl_bind=1
bad_conn_typeerror
invalid_mech_false
