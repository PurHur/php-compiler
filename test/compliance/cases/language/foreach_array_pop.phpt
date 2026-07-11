--TEST--
foreach by-value + array_pop() during iteration drains array like Zend (#13138)
--FILE--
<?php
$a = [1, 2, 3];
foreach ($a as $v) {
    array_pop($a);
}
echo count($a), "\n";
?>
--EXPECT--
0
