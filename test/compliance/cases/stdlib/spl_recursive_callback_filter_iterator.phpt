--TEST--
SPL RecursiveCallbackFilterIterator — callback filter over RecursiveArrayIterator (#6338, #6692)
--FILE--
<?php
var_export(class_exists('RecursiveCallbackFilterIterator', false));
echo "\n";

$inner = new RecursiveArrayIterator([1, 2, 3, 4]);
$it = new RecursiveCallbackFilterIterator($inner, fn ($v) => $v % 2 === 0);
$out = [];
foreach ($it as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";

enum E { case A; }
$inner2 = new RecursiveArrayIterator([1, 2]);
try {
    new RecursiveCallbackFilterIterator($inner2, E::A);
    echo "enum-callback-fail\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

// Nested + ephemeral inline closure shared through getChildren / RecursiveIteratorIterator (#6692).
$nested = new RecursiveArrayIterator(['a' => 1, 'b' => ['c' => 2, 'd' => 3]]);
$filter = new RecursiveCallbackFilterIterator($nested, fn ($v, $k, $iterator) => true);
$leaves = [];
foreach (new RecursiveIteratorIterator($filter) as $k => $v) {
    $leaves[] = $k . '=' . $v;
}
echo implode(' ', $leaves), "\n";

$nested2 = new RecursiveArrayIterator(['b' => ['c' => 2]]);
$filter2 = new RecursiveCallbackFilterIterator($nested2, fn ($v) => true);
$filter2->rewind();
$child = $filter2->getChildren();
$child->rewind();
echo $child->current(), "\n";
--EXPECT--
true
2,4
TypeError
a=1 c=2 d=3
2
