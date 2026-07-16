<?php
$a = new ArrayObject(['b' => 2, 'a' => 1, 'c' => 3]);
$a->asort();
$copy = $a->getArrayCopy();
ksort($copy);
echo $copy === ['a' => 1, 'b' => 2, 'c' => 3] ? "ok\n" : ('fail: ' . var_export($copy, true) . "\n");
