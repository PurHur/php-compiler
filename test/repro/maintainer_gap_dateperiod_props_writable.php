<?php
$s = new DateTimeImmutable('2024-01-01');
$e = new DateTimeImmutable('2024-01-04');
$i = new DateInterval('P1D');
$p = new DatePeriod($s, $i, $e);
foreach (['start', 'current', 'end', 'interval', 'recurrences', 'include_start_date', 'include_end_date'] as $prop) {
    try {
        $p->$prop = $p->$prop;
        echo "$prop=WRITE_OK\n";
    } catch (Error $ex) {
        echo "$prop=ERR_readonly\n";
    }
}
try {
    $p->start = new DateTimeImmutable('2024-01-02');
    $days = [];
    foreach ($p as $d) { $days[] = $d->format('d'); }
    echo 'iter=', implode(',', $days), "\n";
} catch (Throwable $ex) {
    echo "mut_blocked\n";
}
