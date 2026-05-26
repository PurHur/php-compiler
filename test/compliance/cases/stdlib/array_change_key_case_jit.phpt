--TEST--
stdlib array_change_key_case() JIT for string-keyed associative arrays
--FILE--
<?php
$a = array('Foo' => 9, 'Bar' => 3);
$lo = array_change_key_case($a);
echo $lo['foo'], "\n";
echo $lo['bar'], "\n";
--EXPECT--
9
3
