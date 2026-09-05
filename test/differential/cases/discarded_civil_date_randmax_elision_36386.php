<?php
// Discarded getdate / localtime / idate / getrandmax / mt_getrandmax (#36386).
// Side-effect-free statements only. Live shape checks use fixed timestamp.
// php-src: ext/date/php_date.c, ext/standard/datetime.c, ext/random/random.c
// @differential-repeat: 3
function work(int $loops, int $ts): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        getdate();
        getdate($ts);
        localtime();
        localtime($ts);
        localtime($ts, true);
        idate('Y');
        idate('Y', $ts);
        getrandmax();
        mt_getrandmax();
        $c += $k;
    }

    return $c;
}
echo work(5, 1700000000), "\n";
echo work(3, 1700000000), "\n";
echo work(2, 1700000000), "\n";

$ts = 1700000000;
$gd = getdate($ts);
$lt = localtime($ts, true);
echo isset($gd['year'], $gd['mon'], $gd['mday']) ? "1" : "0", "\n";
echo isset($lt['tm_year'], $lt['tm_mon'], $lt['tm_mday']) ? "1" : "0", "\n";
echo idate('Y', $ts), "\n";
echo getrandmax() === mt_getrandmax() ? "1" : "0", "\n";
echo getrandmax() > 0 ? "1" : "0", "\n";
