<?php
$p = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), 2);
foreach ($p as $d) {
    echo $d->format('Y-m-d'), ' ';
}
echo "\n";
