--TEST--
SPL RecursiveIteratorIterator::valid() true at construction — php-src ext/spl/spl_iterators.c (#16904)
--FILE--
<?php
$rii = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, 2]));
var_export($rii->valid());
echo "\n";
$empty = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));
var_export($empty->valid());
echo "\n";
$out = [];
foreach ($rii as $value) {
    $out[] = $value;
}
echo json_encode($out), "\n";
--EXPECT--
true
false
[1,2]
