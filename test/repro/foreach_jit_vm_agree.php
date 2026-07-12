<?php

declare(strict_types=1);

class Agg implements IteratorAggregate
{
    public function getIterator(): Generator
    {
        yield 'k' => 'v';
    }
}

$out = [];
foreach (new Agg() as $k => $v) {
    $out[] = "$k=$v";
}
echo implode(',', $out), "\n";

$g = (function (): Generator {
    yield 1;
    yield 2;
})();
$sum = 0;
foreach ($g as $n) {
    $sum += $n;
}
echo "sum=$sum\n";
