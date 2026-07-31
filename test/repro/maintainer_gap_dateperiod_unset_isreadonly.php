<?php
$p = new DatePeriod(
    new DateTimeImmutable('2024-01-01'),
    new DateInterval('P1D'),
    new DateTimeImmutable('2024-01-03')
);
$rp = new ReflectionProperty('DatePeriod', 'start');
echo 'isReadOnly=', $rp->isReadOnly() ? '1' : '0', "\n";
try {
    $p->start = $p->start;
    echo "write=OK\n";
} catch (Error $e) {
    echo "write=ERR_readonly\n";
}
try {
    unset($p->start);
    echo "unset=OK\n";
} catch (Error $e) {
    echo 'unset=ERR:', $e->getMessage(), "\n";
}
try {
    $v = $p->start;
    echo 'after=', $v === null ? 'null' : 'set', "\n";
} catch (Error $e) {
    echo "after=uninit\n";
}
