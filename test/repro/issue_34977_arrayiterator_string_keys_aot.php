<?php
// #34977 — AOT foreach must emit string keys after packed elements (ArrayIterator + plain HT).
$a = new ArrayIterator([1, 2, 'x' => 3]);
$a->append(4);
echo $a->count(), '|';
foreach ($a as $k => $v) {
    echo $k, '=', $v, ';';
}
echo $a->key() === null ? 'null' : $a->key();
echo "\n";
$b = [1, 2, 'y' => 5];
echo count($b), '|';
foreach ($b as $k => $v) {
    echo $k, '=', $v, ';';
}
echo "\n";
