--TEST--
ArrayObject/ArrayIterator asort/ksort/natsort/natcasesort return true (#19802, ext/spl/spl_array.c)
--FILE--
<?php
$a = new ArrayObject(['b' => 2, 'a' => 1]);
var_export($a->ksort());
echo "\n";
var_export($a->getArrayCopy());
echo "\n";

$b = new ArrayObject(['b' => 2, 'a' => 1]);
var_export($b->asort());
echo "\n";

$c = new ArrayObject(['f10' => 1, 'f2' => 2]);
var_export($c->natsort());
echo "\n";

$d = new ArrayObject(['F10' => 1, 'f2' => 2]);
var_export($d->natcasesort());
echo "\n";

$i = new ArrayIterator(['b' => 2, 'a' => 1]);
var_export($i->ksort());
echo "\n";
var_export($i->getArrayCopy());
echo "\n";
?>
--EXPECT--
true
array (
  'a' => 1,
  'b' => 2,
)
true
true
true
true
array (
  'a' => 1,
  'b' => 2,
)
