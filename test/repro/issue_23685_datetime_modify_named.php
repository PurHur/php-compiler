<?php
/**
 * Repro #23685 — DateTime::modify Zend stub named modifier.
 * php-src: ext/date/php_date.stub.php
 */
foreach (['DateTime', 'DateTimeImmutable'] as $cls) {
    $r = new ReflectionMethod($cls, 'modify');
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $cls, '=', implode(',', $names), "\n";
}
$dt = new DateTime('2020-01-01');
try {
    $out = $dt->modify(modifier: '+1 day');
    echo 'modifier_named=', $out->format('Y-m-d'), "\n";
} catch (Throwable $e) {
    echo 'modifier_fail=', $e->getMessage(), "\n";
}
try {
    $dt->modify(modify: '+1 day');
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo 'legacy=', $e->getMessage(), "\n";
}
$dt2 = new DateTime('2020-01-01');
echo 'positional=', $dt2->modify('+1 day')->format('Y-m-d'), "\n";
