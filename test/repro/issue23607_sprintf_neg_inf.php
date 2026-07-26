<?php
declare(strict_types=1);

// php-src formatted_print.c — float conversions print unsigned INF for -INF (#23607).
$cases = [
    ['%f', -INF],
    ['%F', -INF],
    ['%e', -INF],
    ['%g', -INF],
    ['%+f', -INF],
    ['%+f', INF],
];
foreach ($cases as [$fmt, $v]) {
    echo $fmt, '=>', sprintf($fmt, $v), "\n";
}
echo 'vsprintf=>', vsprintf('%F', [-INF]), "\n";
echo 'nan=>', sprintf('%f', NAN), "\n";
