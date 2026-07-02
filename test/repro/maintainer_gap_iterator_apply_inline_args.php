<?php

declare(strict_types=1);

$it = new ArrayIterator([1, 2, 3]);
$sum = 0;
$count = iterator_apply($it, function (ArrayIterator $iter) use (&$sum): bool {
    $sum += $iter->current();

    return true;
}, [$it]);

if (3 !== $count || 6 !== $sum) {
    echo "fail: count={$count} sum={$sum}\n";
    exit(1);
}

echo "ok\n";
