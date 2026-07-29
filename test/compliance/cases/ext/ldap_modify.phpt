--TEST--
stdlib ldap_mod_* / ldap_rename registration + guards (#21853, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['ldap_mod_add', 'ldap_mod_replace', 'ldap_mod_del', 'ldap_mod_batch', 'ldap_rename'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

echo defined('LDAP_MODIFY_BATCH_ADD') ? "batch_const\n" : "no_batch_const\n";

$link = ldap_connect('ldap://127.0.0.1');

try {
    ldap_mod_add(42, 'cn=x', ['cn' => 'a']);
    echo "mod_bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "mod_bad_conn_typeerror\n";
}

try {
    ldap_rename($link, 'cn=old', 'cn=new', 'dc=example,dc=com');
    echo "rename_type_ok\n";
} catch (Throwable $e) {
    echo "rename_throw\n";
}

ldap_unbind($link);
?>
--EXPECT--
ldap_mod_add=1
ldap_mod_replace=1
ldap_mod_del=1
ldap_mod_batch=1
ldap_rename=1
batch_const
mod_bad_conn_typeerror
rename_type_ok
