--TEST--
ext/ldap ldap_exop_sync/passwd withheld on PROFILE=8.2 (#22731)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);
// Soft-exit: BaseTest ignores --SKIPIF--.
if (!function_exists('ldap_connect')) {
    echo "skip\n";
    exit(0);
}
echo 'ldap_exop=', function_exists('ldap_exop') ? 'Y' : 'N', "\n";
echo 'ldap_exop_sync=', function_exists('ldap_exop_sync') ? 'Y' : 'N', "\n";
echo 'ldap_exop_passwd=', function_exists('ldap_exop_passwd') ? 'Y' : 'N', "\n";
echo 'ldap_exop_whoami=', function_exists('ldap_exop_whoami') ? 'Y' : 'N', "\n";
?>
--EXPECT--
ldap_exop=Y
ldap_exop_sync=N
ldap_exop_passwd=N
ldap_exop_whoami=Y
