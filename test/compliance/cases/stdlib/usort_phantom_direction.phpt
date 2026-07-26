--TEST--
stdlib usort/uasort/uksort reject phantom named direction on 8.2 profile (#23385; vs php-src)
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
?>
--EXPECT--
usort=array,callback n=2
uksort=array,callback n=2
uasort=array,callback n=2
Unknown named parameter $direction
