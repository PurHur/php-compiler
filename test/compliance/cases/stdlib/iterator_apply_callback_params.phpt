--TEST--
stdlib iterator_apply() passes third-arg params to callback (php-src ext/spl/php_spl.c, #11586)
--FILE--
<?php
$it = new ArrayIterator([1, 2, 3]);
$sum = 0;
$count = iterator_apply($it, function ($iter) use (&$sum) {
    $sum += $iter->current();

    return true;
}, [$it]);
echo $count, ' ', $sum, "\n";
?>
--EXPECT--
3 6
