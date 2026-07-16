--TEST--
ArrayObject::asort()/ksort()/natsort()/natcasesort() (#19480, ext/spl/spl_array.c)
--FILE--
<?php
$a = new ArrayObject(['b' => 2, 'a' => 1, 'c' => 3]);
$a->asort();
echo json_encode($a->getArrayCopy()), "\n";

$k = new ArrayObject(['b' => 2, 'a' => 1, 'c' => 3]);
$k->ksort();
echo json_encode($k->getArrayCopy()), "\n";

$n = new ArrayObject(['f10' => 1, 'f2' => 2, 'f1' => 3]);
$n->natsort();
echo json_encode($n->getArrayCopy()), "\n";

$nc = new ArrayObject(['F10' => 1, 'f2' => 2, 'F1' => 3]);
$nc->natcasesort();
echo json_encode($nc->getArrayCopy()), "\n";
?>
--EXPECT--
{"a":1,"b":2,"c":3}
{"a":1,"b":2,"c":3}
{"f10":1,"f2":2,"f1":3}
{"F10":1,"f2":2,"F1":3}
