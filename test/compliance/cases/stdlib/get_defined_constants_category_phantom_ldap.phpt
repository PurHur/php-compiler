--TEST--
get_defined_constants(category: ldap) returns LDAP_ESCAPE_* when ext/ldap loaded (#18173, PHP 8.4 profile)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== 'forward') {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('ldap') ? "ok ext\n" : "fail ext\n";
$ldap = get_defined_constants(category: 'ldap');
echo count($ldap) >= 2 ? "ok category\n" : "fail category\n";
--EXPECT--
ok ext
ok category
