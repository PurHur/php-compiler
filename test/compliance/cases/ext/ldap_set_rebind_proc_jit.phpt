--TEST--
stdlib ldap_set_rebind_proc JIT null-clear (#32148, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_ENABLE_LDAP=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo function_exists('ldap_set_rebind_proc') ? "fn_yes\n" : "fn_no\n";

$link = ldap_connect('ldap://127.0.0.1');

try {
    ldap_set_rebind_proc(42, null);
    echo "bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn_typeerror\n";
}

echo ldap_set_rebind_proc($link, null) ? "clear_ok\n" : "clear_fail\n";

try {
    ldap_set_rebind_proc($link, 42);
    echo "bad_cb_uncaught\n";
} catch (TypeError $e) {
    echo "bad_cb_typeerror\n";
}

ldap_unbind($link);
?>
--EXPECT--
fn_yes
bad_conn_typeerror
clear_ok
bad_cb_typeerror
