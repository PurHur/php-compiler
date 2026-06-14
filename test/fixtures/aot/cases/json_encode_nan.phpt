--TEST--
AOT json_encode() returns false for non-finite float (issue #3606)
--FILE--
<?php
$n = acos(2);
$r = json_encode($n);
echo $r === false ? 'false' : 'other', "\n";
echo json_last_error() === 7 ? '7' : 'n', "\n";
--EXPECT--
false
7
