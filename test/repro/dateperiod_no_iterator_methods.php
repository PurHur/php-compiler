<?php
declare(strict_types=1);
$p = new DatePeriod(new DateTime('2020-01-01 UTC'), new DateInterval('P1D'), 2);
foreach (['rewind', 'valid', 'current', 'key', 'next', 'getIterator'] as $m) {
    echo $m, '=', (int) method_exists($p, $m), "\n";
}
$out = [];
foreach ($p as $d) {
    $out[] = $d->format('Y-m-d');
}
echo 'foreach=', implode(',', $out), "\n";
$it = $p->getIterator();
echo 'it=', get_class($it), "\n";
$out2 = [];
foreach ($it as $d) {
    $out2[] = $d->format('Y-m-d');
}
echo 'it_foreach=', implode(',', $out2), "\n";
