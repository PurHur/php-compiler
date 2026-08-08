--TEST--
Language: dim/prop ?? and ??= keep array/object container (not RHS) (#29112)
--FILE--
<?php
$a = [];
$a['x'] ??= 'y';
echo gettype($a), '|', json_encode($a), "\n";

$a = [];
$x = $a['x'] ?? 'y';
echo gettype($a), '|', json_encode($a), '|', $x, "\n";

$o = new stdClass;
$o->p ??= 'z';
echo gettype($o), '|', json_encode($o), "\n";

$a = [];
$a['x']['y'] ??= 1;
echo gettype($a), '|', json_encode($a), "\n";
--EXPECT--
array|{"x":"y"}
array|[]|y
object|{"p":"z"}
array|{"x":{"y":1}}
