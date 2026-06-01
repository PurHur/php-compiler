--TEST--
stdlib PASSWORD_* constants — defined(), get_defined_constants(), password_hash() (#3620)
--FILE--
<?php
echo defined('PASSWORD_DEFAULT') ? "def_default\n" : "undef_default\n";
echo defined('PASSWORD_BCRYPT') ? "def_bcrypt\n" : "undef_bcrypt\n";

$core = get_defined_constants(true);
echo isset($core['Core']['PASSWORD_DEFAULT']) ? "core_default\n" : "missing_default\n";
echo isset($core['Core']['PASSWORD_BCRYPT']) ? "core_bcrypt\n" : "missing_bcrypt\n";

$hash = password_hash('secret', PASSWORD_DEFAULT);
echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";

$hash2 = password_hash('other', PASSWORD_BCRYPT);
echo password_verify('other', $hash2) ? "bcrypt_ok\n" : "bcrypt_fail\n";
--EXPECT--
def_default
def_bcrypt
core_default
core_bcrypt
verify_ok
bcrypt_ok
