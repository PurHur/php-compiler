--TEST--
stdlib ldap_get_option registration + set/get round-trip (#21851, ext/ldap/ldap.c)
--ENV--
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

try {
    ldap_get_option(42, LDAP_OPT_PROTOCOL_VERSION, $val);
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
bad_conn_typeerror
