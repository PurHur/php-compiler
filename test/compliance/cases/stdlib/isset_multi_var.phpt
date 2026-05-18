--TEST--
stdlib isset() with multiple variables (short-circuit)
--FILE--
<?php
$a = 1;
$b = 2;
if (isset($a, $b)) {
    echo "both\n";
}
$c = null;
if (isset($a, $c)) {
    echo "fail\n";
} else {
    echo "null\n";
}
$d = 3;
if (isset($a, $b, $d)) {
    echo "three\n";
}
--EXPECT--
both
null
three
