<?php
// Zend parity: ext/standard/password.c PASSWORD_* constants (#3620).
echo defined('PASSWORD_DEFAULT') ? "def_default\n" : "undef_default\n";
echo defined('PASSWORD_BCRYPT') ? "def_bcrypt\n" : "undef_bcrypt\n";

$core = get_defined_constants(true);
echo isset($core['Core']['PASSWORD_DEFAULT']) ? "core_default\n" : "missing_default\n";

$hash = password_hash('secret', PASSWORD_DEFAULT);
echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";
