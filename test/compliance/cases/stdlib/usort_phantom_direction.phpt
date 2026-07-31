--TEST--
stdlib usort/uasort/uksort reject phantom direction on all profiles (#23385, #26142; vs php-src)
--FILE--
<?php
foreach (['usort', 'uksort', 'uasort'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())),
        ' n=', $r->getNumberOfParameters(), "\n";
}
$a = [3, 1];
try {
    usort(array: $a, callback: static fn ($x, $y) => $x <=> $y, direction: 1);
    echo "direction_accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
$b = [3, 1];
try {
    usort($b, static fn ($x, $y) => $x <=> $y, 1);
    echo "positional_accepted\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
usort=array,callback n=2
uksort=array,callback n=2
uasort=array,callback n=2
Unknown named parameter $direction
ArgumentCountError: usort() expects exactly 2 arguments, 3 given
