<?php
echo class_exists('ArrayIterator', false) ? 'yes' : 'no', "\n";

$it = new ArrayIterator([1, 2, 3]);
$out = [];
foreach ($it as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
echo $it->count(), "\n";

$it2 = new ArrayIterator(['a' => 10, 'b' => 20]);
$it2->rewind();
echo $it2->key(), ':', $it2->current(), "\n";
$it2->next();
echo $it2->key(), ':', $it2->current(), "\n";

enum E: int { case A = 1; case B = 2; }
$enumIt = new ArrayIterator([E::A, E::B]);
var_export(iterator_to_array($enumIt));
echo "\n";
