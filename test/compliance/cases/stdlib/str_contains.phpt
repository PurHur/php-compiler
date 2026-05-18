--TEST--
stdlib str_contains()
--FILE--
<?php
echo str_contains('hello', 'ell') ? 'y' : 'n', "\n";
echo str_contains('hello', 'z') ? 'y' : 'n', "\n";
echo str_contains('', '') ? 'y' : 'n', "\n";
--EXPECT--
y
n
y
