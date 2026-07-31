--TEST--
date DatePeriod public props are readonly — Zend Error (#26146, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$s = new DateTimeImmutable('2024-01-01');
$e = new DateTimeImmutable('2024-01-04');
$i = new DateInterval('P1D');
$p = new DatePeriod($s, $i, $e);
foreach (['start', 'current', 'end', 'interval', 'recurrences', 'include_start_date', 'include_end_date'] as $prop) {
    try {
        $p->$prop = $p->$prop;
        echo "$prop=WRITE_OK\n";
    } catch (Error $ex) {
        $msg = $ex->getMessage();
        echo str_contains($msg, 'Cannot modify readonly property DatePeriod::$' . $prop)
            ? "$prop=ERR_readonly\n"
            : "$prop=ERR_other:" . $msg . "\n";
    }
}
try {
    $p->start = new DateTimeImmutable('2024-01-02');
    $days = [];
    foreach ($p as $d) {
        $days[] = $d->format('d');
    }
    echo 'iter=', implode(',', $days), "\n";
} catch (Throwable $ex) {
    echo "mut_blocked\n";
}
// Iteration still works when props are not mutated from userland.
$days = [];
foreach ($p as $d) {
    $days[] = $d->format('d');
}
echo 'iter_ok=', implode(',', $days), "\n";
$rp = new ReflectionProperty(DatePeriod::class, 'start');
echo 'refl_ro=', $rp->isReadOnly() ? '1' : '0', "\n";
?>
--EXPECT--
start=ERR_readonly
current=ERR_readonly
end=ERR_readonly
interval=ERR_readonly
recurrences=ERR_readonly
include_start_date=ERR_readonly
include_end_date=ERR_readonly
mut_blocked
iter_ok=01,02,03
refl_ro=1
