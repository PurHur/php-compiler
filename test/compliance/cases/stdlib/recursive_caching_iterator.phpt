--TEST--
SPL RecursiveCachingIterator — cache + RecursiveIterator children (#6915)
--FILE--
<?php
var_export(class_exists('RecursiveCachingIterator', false));
echo "\n";

$it = new RecursiveCachingIterator(new RecursiveArrayIterator([1, [2, 3], 4]));
$out = [];
foreach ($it as $k => $v) {
    $out[] = $k . '=' . (is_array($v) ? 'arr' : $v) . ':' . ($it->hasChildren() ? 'y' : 'n');
}
echo implode(' ', $out), "\n";

$it2 = new RecursiveCachingIterator(new RecursiveArrayIterator([1, [2, 3], 4]));
$it2->rewind();
$it2->next(); // at [2,3]
$child = $it2->getChildren();
$nested = [];
foreach ($child as $v) {
    $nested[] = $v;
}
echo implode(',', $nested), "\n";

try {
    new RecursiveCachingIterator(new ArrayIterator([1]));
    echo "non-recursive-ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
true
0=1:n 1=arr:y 2=4:n
2,3
TypeError
