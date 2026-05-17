--TEST--
stdlib is_bool() type check
--FILE--
<?php
echo is_bool(true) ? 'y' : 'n', "\n";
echo is_bool(false) ? 'y' : 'n', "\n";
echo is_bool(1) ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
