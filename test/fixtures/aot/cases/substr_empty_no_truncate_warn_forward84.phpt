--TEST--
AOT: substr() empty/null/past-end start returns '' on PROFILE=8.4 (#22489)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$null = null;
$empty = '';
$ab = 'ab';
$hello = 'hello';
echo '[', substr($null, 0, 1), ']', "\n";
echo '[', substr($empty, 0, 1), ']', "\n";
echo '[', substr($ab, 5, 1), ']', "\n";
echo '[', substr($ab, 2, 1), ']', "\n";
echo '[', substr($hello, 0, 50), ']', "\n";
?>
--EXPECT--
[]
[]
[]
[]
[hello]
