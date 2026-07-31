--TEST--
stdlib ldap_get_attributes registration + TypeError guards (#21850, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_ENABLE_LDAP=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo function_exists('ldap_get_attributes') ? "registered\n" : "missing\n";

$link = ldap_connect('ldap://127.0.0.1');

try {
    ldap_get_attributes(42, $link);
    echo "bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn_typeerror\n";
}

try {
    ldap_get_attributes($link, 42);
    echo "bad_entry_uncaught\n";
} catch (TypeError $e) {
    echo "bad_entry_typeerror\n";
}

ldap_unbind($link);
?>
--EXPECT--
registered
bad_conn_typeerror
bad_entry_typeerror
