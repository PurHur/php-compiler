--TEST--
stdlib ldap_set_rebind_proc + *_ext surface (#22226, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_ENABLE_LDAP=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$fns = [
    'ldap_set_rebind_proc',
    'ldap_add_ext',
    'ldap_delete_ext',
    'ldap_rename_ext',
    'ldap_mod_add_ext',
    'ldap_mod_del_ext',
    'ldap_mod_replace_ext',
];
foreach ($fns as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

$link = ldap_connect('ldap://127.0.0.1');

try {
    ldap_set_rebind_proc(42, null);
    echo "bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn_typeerror\n";
}

echo ldap_set_rebind_proc($link, null) ? "clear_ok\n" : "clear_fail\n";
echo ldap_set_rebind_proc($link, static function ($ldap, $url) {
    return 0;
}) ? "set_ok\n" : "set_fail\n";

try {
    ldap_set_rebind_proc($link, 42);
    echo "bad_cb_uncaught\n";
} catch (TypeError $e) {
    echo "bad_cb_typeerror\n";
}

ldap_unbind($link);
?>
--EXPECT--
ldap_set_rebind_proc=1
ldap_add_ext=1
ldap_delete_ext=1
ldap_rename_ext=1
ldap_mod_add_ext=1
ldap_mod_del_ext=1
ldap_mod_replace_ext=1
bad_conn_typeerror
clear_ok
set_ok
bad_cb_typeerror
