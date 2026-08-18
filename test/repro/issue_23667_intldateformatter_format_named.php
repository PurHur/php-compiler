<?php
/**
 * Repro #23667 — IntlDateFormatter::format Reflection $datetime + named datetime:.
 * php-src ext/intl/dateformat/dateformat.stub.php
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_23667_intldateformatter_format_named.php'
 */
if (!class_exists('IntlDateFormatter')) {
    echo "MISSING\n";
    exit(0);
}
$rf = new ReflectionMethod(IntlDateFormatter::class, 'format');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
$fmt = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::NONE,
    'UTC',
    IntlDateFormatter::GREGORIAN,
    'yyyy-MM-dd'
);
$dt = new DateTime('2020-01-15 UTC');
try {
    echo 'datetime=', $fmt->format(datetime: $dt), "\n";
} catch (Throwable $e) {
    echo 'datetime:', $e->getMessage(), "\n";
}
try {
    echo 'args=', $fmt->format(args: $dt), "\n";
} catch (Throwable $e) {
    echo 'args:', $e->getMessage(), "\n";
}
echo 'pos=', $fmt->format($dt), "\n";
