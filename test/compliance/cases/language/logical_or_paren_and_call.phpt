--TEST--
language false || (func() && …) yields bool not callee name (#25850, Zend/zend_operators.c)
--FILE--
<?php
$a = ["a", "b"];
function show($label, $v) {
    echo $label, ":";
    var_export($v);
    echo " (", gettype($v), ")\n";
}
show("A", false || (is_array($a) && false));
show("B", false || (is_array($a) && true));
show("C", false || (count($a) === 2 && false));
show("D", (is_array($a) && false));
$t = (is_array($a) && false);
show("E", false || $t);
show("F", false || (is_array($a)));
show("G", false or (is_array($a) && false));
$row = ["x"];
show("H", $row === [null] || (is_array($row) && count($row) === 1 && $row[0] === null));
?>
--EXPECT--
A:false (boolean)
B:true (boolean)
C:false (boolean)
D:false (boolean)
E:false (boolean)
F:true (boolean)
G:false (boolean)
H:false (boolean)
