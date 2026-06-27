<?php
declare(strict_types=1);

// Repro for #12757 — IteratorIterator::rewind() + foreach (ext/spl/spl_iterators.c).
$it = new IteratorIterator(new ArrayIterator([1, 2]));
$it->rewind();
$out = '';
foreach ($it as $v) {
    $out .= (string) $v;
}
if ($out !== '12') {
    echo 'fail: IteratorIterator expected 12, got ', $out, PHP_EOL;
    exit(1);
}

$rii = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2, 3]]));
$rii->rewind();
$out2 = '';
foreach ($rii as $v) {
    $out2 .= (string) $v;
}
if ($out2 !== '123') {
    echo 'fail: RecursiveIteratorIterator expected 123, got ', $out2, PHP_EOL;
    exit(1);
}
echo $out, PHP_EOL;
