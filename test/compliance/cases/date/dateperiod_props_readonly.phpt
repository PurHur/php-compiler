--TEST--
date DatePeriod handler-readonly typed props — write Error, unset→uninit, isReadOnly false (#26146/#26154/#26170)
--FILE--
<?php
declare(strict_types=1);
$s = new DateTimeImmutable('2024-01-01');
$e = new DateTimeImmutable('2024-01-04');
$i = new DateInterval('P1D');
$p = new DatePeriod($s, $i, $e);
// Iterate a sibling period before unset/Error paths that may drop DateTime refs (#26170).
$p2 = new DatePeriod(
    new DateTimeImmutable('2024-01-01'),
    new DateInterval('P1D'),
    new DateTimeImmutable('2024-01-04')
);
$days = [];
foreach ($p2 as $d) {
    $days[] = $d->format('d');
}
echo 'iter_ok=', implode(',', $days), "\n";
foreach (['start', 'current', 'end', 'interval', 'recurrences', 'include_start_date', 'include_end_date'] as $prop) {
    try {
        $p->$prop = $p->$prop;
        echo "$prop=WRITE_OK\n";
    } catch (Error $ex) {
        echo "$prop=ERR_readonly\n";
    }
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
$rp = new ReflectionProperty('DatePeriod', 'start');
echo 'refl_ro=', $rp->isReadOnly() ? '1' : '0', "\n";
echo 'refl_type=', $rp->hasType() ? (string) $rp->getType() : 'none', "\n";
?>
--EXPECT--
iter_ok=01,02,03
start=ERR_readonly
current=ERR_readonly
end=ERR_readonly
interval=ERR_readonly
recurrences=ERR_readonly
include_start_date=ERR_readonly
include_end_date=ERR_readonly
unset=OK
after_unset=uninit
refl_ro=0
refl_type=?DateTimeInterface
