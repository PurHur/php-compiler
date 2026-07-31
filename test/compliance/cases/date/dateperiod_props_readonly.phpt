--TEST--
date DatePeriod handler-readonly props — write Error, unset OK, isReadOnly false (#26146/#26154)
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
        echo "$prop=ERR_readonly\n";
    }
}
try {
    $p->start = new DateTimeImmutable('2024-01-02');
    echo "mut=WRITE_OK\n";
} catch (Throwable $ex) {
    echo "mut_blocked\n";
}
try {
    unset($p->start);
    echo "unset=OK\n";
} catch (Error $ex) {
    echo "unset=ERR\n";
}
try {
    $v = $p->start;
    echo 'after_unset=', null === $v ? 'null' : 'set', "\n";
} catch (Error $ex) {
    echo "after_unset=uninit\n";
}
$p2 = new DatePeriod($s, $i, $e);
$days = [];
foreach ($p2 as $d) {
    $days[] = $d->format('d');
}
echo 'iter_ok=', implode(',', $days), "\n";
$rp = new ReflectionProperty('DatePeriod', 'start');
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
unset=OK
after_unset=null
iter_ok=01,02,03
refl_ro=0
