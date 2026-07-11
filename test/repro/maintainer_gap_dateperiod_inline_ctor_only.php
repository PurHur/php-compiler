<?php

declare(strict_types=1);

$count = 0;
foreach (new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), new DateTime('2020-01-03')) as $d) {
    ++$count;
}

$ok = 2 === $count;

echo $ok ? "ok count=$count\n" : "fail: count=$count\n";
exit($ok ? 0 : 1);
