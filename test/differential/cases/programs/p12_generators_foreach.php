<?php
// #36221 program: generators feeding foreach + getReturn
function rangeSquares(int $n): \Generator {
    $sum = 0;
    for ($i = 1; $i <= $n; $i++) {
        $v = $i * $i;
        $sum += $v;
        yield $i => $v;
    }
    return $sum;
}
function chain(\Generator ...$gens): \Generator {
    foreach ($gens as $g) {
        yield from $g;
    }
}
$g = rangeSquares(5);
$lines = [];
foreach ($g as $k => $v) {
    $lines[] = "$k=$v";
}
$lines[] = 'ret=' . $g->getReturn();
$g2 = chain(rangeSquares(2), rangeSquares(3));
$acc = [];
foreach ($g2 as $k => $v) {
    $acc[] = "$k:$v";
}
$lines[] = 'chain=' . implode(',', $acc);
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
