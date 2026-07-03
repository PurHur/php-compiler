--TEST--
AOT: strlen() on {main} script global variable (#15632)
--FILE--
<?php
$x = 'abc';
echo strlen($x), "\n";
echo "after\n";
--EXPECT--
3
after
