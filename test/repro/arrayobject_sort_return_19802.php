<?php
$a = new ArrayObject(['b' => 2, 'a' => 1]);
var_export($a->ksort());
echo "\n";
var_export($a->getArrayCopy());
echo "\n";
$i = new ArrayIterator(['b' => 2, 'a' => 1]);
var_export($i->asort());
echo "\n";
