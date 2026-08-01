--TEST--
date DatePeriod write-reject + unset + typed-uninit catch keeps DateTime refs (#26203)
--FILE--
<?php
declare(strict_types=1);
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
    echo 'end=', $p->end->format('Y-m-d'), "\n";
} catch (Throwable $ex) {
    echo 'FAIL_END:', $ex->getMessage(), "\n";
}
try {
    echo 's=', $s->format('Y-m-d'), "\n";
} catch (Throwable $ex) {
    echo 'FAIL_S:', $ex->getMessage(), "\n";
}
try {
    echo 'e=', $e->format('Y-m-d'), "\n";
} catch (Throwable $ex) {
    echo 'FAIL_E:', $ex->getMessage(), "\n";
}
?>
--EXPECT--
end=2024-01-04
s=2024-01-01
e=2024-01-04
