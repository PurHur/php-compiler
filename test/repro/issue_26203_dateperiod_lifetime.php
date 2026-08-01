<?php
/**
 * #26203 — DatePeriod write-reject → unset → typed-uninit catch must not destroyForGc
 * still-live DatePeriod / DateTimeImmutable locals (php-src-strict).
 */
$s = new DateTimeImmutable('2024-01-01');
$e = new DateTimeImmutable('2024-01-04');
$i = new DateInterval('P1D');
$p = new DatePeriod($s, $i, $e);
try {
    $p->start = $p->start;
} catch (Error $ex) {
}
unset($p->start);
try {
    $v = $p->start;
} catch (Error $ex) {
}
try {
    echo $p->end->format('Y-m-d'), "\n";
} catch (Throwable $ex) {
    echo 'FAIL:', $ex->getMessage(), "\n";
}
try {
    echo $s->format('Y-m-d'), "\n";
} catch (Throwable $ex) {
    echo 'FAIL_S:', $ex->getMessage(), "\n";
}
