--TEST--
isset()/empty() on string offsets without OOB read warnings (#5307)
--FILE--
<?php
$s = 'abc';
echo isset($s[0]) ? "true\n" : "false\n";
echo isset($s[99]) ? "true\n" : "false\n";

$s = 'hello';
echo empty($s[99]) ? "true\n" : "false\n";
--EXPECT--
true
false
true
