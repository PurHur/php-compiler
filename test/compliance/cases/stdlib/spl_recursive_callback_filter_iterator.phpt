--TEST--
SPL RecursiveCallbackFilterIterator — callback filter over RecursiveArrayIterator (#6338)
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
--EXPECT--
true
2,4
TypeError
