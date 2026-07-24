--TEST--
AOT: array_first_key()/array_last_key() phantom on PHP 8.4 forward profile (#22793)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('array_first_key') ? "fail_first\n" : "ok_first\n";
echo function_exists('array_last_key') ? "fail_last\n" : "ok_last\n";
$a = ['x' => 1, 'y' => 2];
echo array_key_first($a), "\n";
echo array_key_last($a), "\n";
--EXPECT--
ok_first
ok_last
x
y
