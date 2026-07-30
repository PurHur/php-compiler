<?php
declare(strict_types=1);

$s = new DateTime('2024-01-01');
$i = new DateInterval('P1D');
$e = new DateTime('2024-01-04');
try {
    $p = new DatePeriod(start: $s, interval: $i, end: $e);
    $n = 0;
    foreach ($p as $_) {
        ++$n;
    }
    echo 'named_end_ok count=', $n, "\n";
} catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
}
try {
    new DatePeriod(start: $s, interval: $i, recur: 2);
    echo "recur_ok\n";
} catch (Throwable $ex) {
    echo 'recur: ', $ex->getMessage(), "\n";
}
$r = new ReflectionMethod(DatePeriod::class, '__construct');
foreach ($r->getParameters() as $p) {
    echo $p->getName(),
        ' opt=', $p->isOptional() ? '1' : '0',
        ' type=', $p->hasType() ? (string) $p->getType() : '-',
        ' def=', $p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-',
        "\n";
}
$pos = new DatePeriod($s, $i, 2);
$n = 0;
foreach ($pos as $_) {
    ++$n;
}
echo 'pos_recur count=', $n, "\n";
