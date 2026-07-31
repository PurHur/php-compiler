--TEST--
stdlib ldap_connect returns LDAP\Connection via libldap FFI (#3369, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_ENABLE_LDAP=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo function_exists('ldap_connect') ? "fn_yes\n" : "fn_no\n";
echo extension_loaded('ldap') ? "ext_yes\n" : "ext_no\n";
echo class_exists('LDAP\\Connection') ? "class_yes\n" : "class_no\n";

$link = ldap_connect('ldap://127.0.0.1');
echo is_object($link) ? "obj_yes\n" : "obj_no\n";
echo ($link instanceof LDAP\Connection) ? "instanceof_yes\n" : "instanceof_no\n";

ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
echo "setopt_ok\n";
echo ldap_errno($link), "\n";
echo ldap_err2str(0) === 'Success' || ldap_err2str(0) === 'success' ? "err2str_ok\n" : ("err2str=".ldap_err2str(0)."\n");

$ok = @ldap_bind($link);
echo $ok ? "bind_ok\n" : "bind_fail\n";
echo ldap_errno($link) !== 0 ? "errno_set\n" : "errno_zero\n";
echo ldap_unbind($link) ? "unbind_ok\n" : "unbind_fail\n";
?>
--EXPECT--
fn_yes
ext_yes
class_yes
obj_yes
instanceof_yes
setopt_ok
0
err2str_ok
bind_fail
errno_set
unbind_ok
