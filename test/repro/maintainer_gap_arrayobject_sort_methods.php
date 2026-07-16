<?php

$a = new ArrayObject(['b' => 2, 'a' => 1, 'c' => 3]);
$a->asort();
var_export($a->getArrayCopy());
echo "\n";
