--TEST--
stdlib ldap_exop/ldap_exop_sync/ldap_parse_exop/ldap_exop_whoami/ldap_exop_refresh (#8688, #21835, #22731)
--ENV--
PHP_COMPILER_ENABLE_LDAP=1
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
declare(strict_types=1);

foreach (['ldap_exop', 'ldap_exop_sync', 'ldap_parse_exop', 'ldap_exop_whoami', 'ldap_exop_refresh', 'ldap_exop_passwd'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
echo defined('LDAP_EXOP_WHO_AM_I') ? "oid_yes\n" : "oid_no\n";

$link = ldap_connect('ldap://127.0.0.1');
$data = null;
$oid = null;
$ok = @ldap_exop_sync($link, LDAP_EXOP_WHO_AM_I, null, null, $data, $oid);
echo $ok ? "sync_ok\n" : "sync_fail\n";
echo ldap_errno($link) !== 0 ? "errno_set\n" : "errno_zero\n";

try {
    ldap_exop(42, 'x');
    echo "bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn_typeerror\n";
}

try {
    ldap_parse_exop($link, 42);
    echo "bad_result_uncaught\n";
} catch (TypeError $e) {
    echo "bad_result_typeerror\n";
}

$ttl = @ldap_exop_refresh($link, 'cn=x', 30);
echo false === $ttl ? "refresh_fail\n" : "refresh_ok\n";

$who = @ldap_exop_whoami($link);
echo false === $who ? "whoami_fail\n" : "whoami_ok\n";

$pw = @ldap_exop_passwd($link);
echo false === $pw ? "passwd_fail\n" : "passwd_ok\n";
ldap_unbind($link);
?>
--EXPECT--
ldap_exop=1
ldap_exop_sync=1
ldap_parse_exop=1
ldap_exop_whoami=1
ldap_exop_refresh=1
ldap_exop_passwd=1
oid_yes
sync_fail
errno_set
bad_conn_typeerror
bad_result_typeerror
refresh_fail
whoami_fail
passwd_fail
