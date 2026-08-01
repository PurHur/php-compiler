--TEST--
ini_alter named option/value arguments (JIT, issue #26465)
--FILE--
<?php
$old = ini_alter(option: 'display_errors', value: '0');
echo (is_string($old) || false === $old) ? "named_ok\n" : "named_bad\n";
--EXPECT--
named_ok
