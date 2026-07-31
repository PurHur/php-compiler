--TEST--
ext ldap ldap_escape filter and DN escaping (issue #6352, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_ENABLE_LDAP=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('ldap_escape') ? "yes\n" : "no\n";
echo extension_loaded('ldap') ? "ext_yes\n" : "ext_no\n";
var_dump(ldap_escape('(a=b)', '', LDAP_ESCAPE_FILTER));
var_dump(ldap_escape('cn=admin,ou=people', '', LDAP_ESCAPE_DN));
var_dump(ldap_escape(''));
echo LDAP_ESCAPE_FILTER, "\n";
echo LDAP_ESCAPE_DN, "\n";
enum E: string { case A = 'x'; }
try {
    ldap_escape(E::A);
    echo "enum_uncaught\n";
} catch (TypeError $e) {
    echo "enum_typeerror\n";
}
?>
--EXPECT--
yes
ext_yes
string(9) "\28a=b\29"
string(24) "cn\3dadmin\2cou\3dpeople"
string(0) ""
1
2
enum_typeerror
