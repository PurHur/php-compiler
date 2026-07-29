--TEST--
stdlib ldap_compare/parse_result/get_dn/attribute helpers (#22177, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$fns = [
    'ldap_compare',
    'ldap_parse_result',
    'ldap_get_dn',
    'ldap_first_attribute',
    'ldap_next_attribute',
    'ldap_get_values',
    'ldap_get_values_len',
];
foreach ($fns as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

$link = ldap_connect('ldap://127.0.0.1');

try {
    ldap_compare(42, 'cn=x', 'cn', 'x');
    echo "compare_bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "compare_bad_conn_typeerror\n";
}

$r = ldap_compare($link, 'cn=x,dc=example,dc=com', 'cn', 'x');
echo (-1 === $r) ? "compare_error_int\n" : "compare_unexpected\n";

try {
    ldap_get_dn($link, $link);
    echo "get_dn_bad_entry_uncaught\n";
} catch (TypeError $e) {
    echo "get_dn_bad_entry_typeerror\n";
}

try {
    ldap_next_attribute($link, $link);
    echo "next_attr_bad_entry_uncaught\n";
} catch (TypeError $e) {
    echo "next_attr_bad_entry_typeerror\n";
}

ldap_unbind($link);
?>
--EXPECT--
ldap_compare=1
ldap_parse_result=1
ldap_get_dn=1
ldap_first_attribute=1
ldap_next_attribute=1
ldap_get_values=1
ldap_get_values_len=1
compare_bad_conn_typeerror
compare_error_int
get_dn_bad_entry_typeerror
next_attr_bad_entry_typeerror
