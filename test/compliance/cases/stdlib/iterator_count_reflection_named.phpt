--TEST--
iterator_count Reflection $iterator + named arg (#23423, php-src-strict)
--FILE--
<?php
$rf = new ReflectionFunction('iterator_count');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$it = new ArrayIterator([1, 2, 3]);
echo iterator_count(iterator: $it), "\n";
try {
    iterator_count(it: $it);
    echo "unexpected it ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
iterator
3
Error
