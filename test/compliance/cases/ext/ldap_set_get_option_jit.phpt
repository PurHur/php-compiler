--TEST--
stdlib ldap_set_option/ldap_get_option JIT int subset (#32107, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_ENABLE_LDAP=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo function_exists('ldap_set_option') ? "set_yes\n" : "set_no\n";
echo function_exists('ldap_get_option') ? "get_yes\n" : "get_no\n";

$link = ldap_connect('ldap://127.0.0.1');
$val = 0;
$okSet = ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
$okGet = ldap_get_option($link, LDAP_OPT_PROTOCOL_VERSION, $val);
echo $okSet ? "set_ok\n" : "set_fail\n";
echo $okGet ? "get_ok\n" : "get_fail\n";
echo 3 === $val ? "val_3\n" : "val_other\n";

$nullSet = @ldap_set_option(null, LDAP_OPT_PROTOCOL_VERSION, 3);
$nullVal = 0;
$nullGet = @ldap_get_option(null, LDAP_OPT_PROTOCOL_VERSION, $nullVal);
echo $nullSet ? "null_set_ok\n" : "null_set_fail\n";
echo $nullGet ? "null_get_ok\n" : "null_get_fail\n";
echo 3 === $nullVal ? "null_val_3\n" : "null_val_other\n";

try {
    ldap_set_option(42, LDAP_OPT_PROTOCOL_VERSION, 3);
    echo "bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn_typeerror\n";
}

ldap_unbind($link);
?>
--EXPECT--
set_yes
get_yes
set_ok
get_ok
val_3
null_set_ok
null_get_ok
null_val_3
bad_conn_typeerror
