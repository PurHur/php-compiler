--TEST--
ArrayObject asort/ksort in-place (#19480, ext/spl/spl_array.c)
--FILE--
<?php
$a = new ArrayObject(['b' => 2, 'a' => 1, 'c' => 3]);
$a->asort();
$copy = $a->getArrayCopy();
ksort($copy);
echo json_encode($copy), "\n";

$a4 = new ArrayObject(['b' => 2, 'a' => 1, 'c' => 3]);
$a4->ksort();
echo json_encode($a4->getArrayCopy()), "\n";
?>
--EXPECT--
{"a":1,"b":2,"c":3}
{"a":1,"b":2,"c":3}
