--TEST--
RecursiveIteratorIterator mode OR CATCH_GET_CHILD yields leaf objects (#24293)
--FILE--
<?php
$mode = RecursiveIteratorIterator::LEAVES_ONLY | RecursiveIteratorIterator::CATCH_GET_CHILD;
$leaf = [];
foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator([new stdClass()]), $mode) as $v) {
    $leaf[] = is_object($v) ? get_class($v) : gettype($v);
}
echo count($leaf), ' ', $leaf[0] ?? 'none', "\n";
$nested = [];
foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator([[1]]), $mode) as $v) {
    $nested[] = is_array($v) ? json_encode($v) : var_export($v, true);
}
echo count($nested), ' ', $nested[0] ?? 'none', "\n";
$plain = [];
foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator([[1], 2])) as $v) {
    $plain[] = $v;
}
echo json_encode($plain), "\n";
--EXPECT--
1 stdClass
1 [1]
[1,2]
