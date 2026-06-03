--TEST--
language: null array keys coerce to empty string (issue #5269, zend_hash.c)
--FILE--
<?php
$a = [null => 1];
echo json_encode($a), "\n";
echo array_key_exists('', $a) ? "exists\n" : "missing\n";

$b = [];
$b[null] = 2;
echo array_key_exists('', $b) ? "assign_ok\n" : "assign_bad\n";

foreach ([null => 3] as $k => $v) {
    echo json_encode($k), "\n";
}
--EXPECT--
{"":1}
exists
assign_ok
""
