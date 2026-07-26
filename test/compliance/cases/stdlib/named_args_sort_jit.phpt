--TEST--
sort/rsort named array/flags; reject phantom direction (JIT, issue #23225)
--FILE--
<?php
foreach (['sort', 'rsort'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), PHP_EOL;
}
$a = [3, 1, 2];
sort(array: $a, flags: SORT_NUMERIC);
echo implode(',', $a), PHP_EOL;
$b = [3, 1, 2];
rsort(array: $b, flags: SORT_NUMERIC);
echo implode(',', $b), PHP_EOL;
try {
    $c = [2, 1];
    sort(array: $c, direction: 1);
    echo "direction_accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
sort:array,flags
rsort:array,flags
1,2,3
3,2,1
Unknown named parameter $direction
