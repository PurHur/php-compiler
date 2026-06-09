--TEST--
stdlib ini_restore() resets local INI to php.ini default (issue #3205)
--FILE--
<?php
$orig = ini_get('display_errors');
ini_set('display_errors', '0');
ini_restore('display_errors');
echo ini_get('display_errors') === $orig ? "restore_ok\n" : "restore_fail\n";
ini_restore('unknown_ini_key');
echo "unknown_ok\n";
--EXPECT--
restore_ok
unknown_ok
