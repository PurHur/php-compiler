<?php
// #34482 — AOT DateTime::format / date() with runtime format must not SIGSEGV.
$d = DateTimeImmutable::createFromTimestamp(1700000000.5);
$fmts = ['U', 'u', 'U.u', 'Y-m-d', 'H:i:s.u'];
foreach ($fmts as $f) {
    echo 'dt:', $f, '=', $d->format($f), "\n";
}
$dateFmts = ['Y-m-d', 'U', 'H:i:s'];
foreach ($dateFmts as $f) {
    echo 'date:', $f, '=', date($f, 1700000000), "\n";
}
