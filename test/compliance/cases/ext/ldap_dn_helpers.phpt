--TEST--
ext ldap ldap_dn2ufn / ldap_explode_dn DN helpers (issue #22212, php-src ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('ldap_dn2ufn') ? "dn2ufn_yes\n" : "dn2ufn_NO\n";
echo function_exists('ldap_explode_dn') ? "explode_yes\n" : "explode_NO\n";

var_dump(ldap_dn2ufn("cn=bob,dc=example,dc=com"));
var_dump(ldap_dn2ufn("cn=bob,ou=users,dc=example,dc=com"));
var_dump(ldap_dn2ufn("cn=<bob>,dc=example,dc=com"));
var_dump(ldap_dn2ufn("bob,dc=example,dc=com"));

var_dump(ldap_explode_dn("cn=bob,dc=example,dc=com", 0));
var_dump(ldap_explode_dn("cn=bob,ou=users,dc=example,dc=com", 0));
var_dump(ldap_explode_dn("cn=bob,dc=example,dc=com", 1));
var_dump(ldap_explode_dn("cn=bob,ou=users,dc=example,dc=com", 1));
var_dump(ldap_explode_dn("cn=<bob>,dc=example,dc=com", 0));
var_dump(ldap_explode_dn("bob,dc=example,dc=com", 1));
?>
--EXPECT--
dn2ufn_yes
explode_yes
string(16) "bob, example.com"
string(23) "bob, users, example.com"
bool(false)
bool(false)
array(4) {
  ["count"]=>
  int(3)
  [0]=>
  string(6) "cn=bob"
  [1]=>
  string(10) "dc=example"
  [2]=>
  string(6) "dc=com"
}
array(5) {
  ["count"]=>
  int(4)
  [0]=>
  string(6) "cn=bob"
  [1]=>
  string(8) "ou=users"
  [2]=>
  string(10) "dc=example"
  [3]=>
  string(6) "dc=com"
}
array(4) {
  ["count"]=>
  int(3)
  [0]=>
  string(3) "bob"
  [1]=>
  string(7) "example"
  [2]=>
  string(3) "com"
}
array(5) {
  ["count"]=>
  int(4)
  [0]=>
  string(3) "bob"
  [1]=>
  string(5) "users"
  [2]=>
  string(7) "example"
  [3]=>
  string(3) "com"
}
bool(false)
bool(false)
