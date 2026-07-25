--TEST--
IntlDateFormatter::format / datefmt_format accept localtime() arrays (#22870)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlDateFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$ts = strtotime('2024-07-24 12:00:00 UTC');
$lt = localtime($ts, true);
$oop = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::NONE,
    'UTC',
    IntlDateFormatter::GREGORIAN,
    'yyyy-MM-dd'
);
$proc = datefmt_create(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::NONE,
    'UTC',
    IntlDateFormatter::GREGORIAN,
    'yyyy-MM-dd'
);
echo 'oop=', $oop->format($lt), "\n";
echo 'proc=', datefmt_format($proc, $lt), "\n";
echo 'ts=', $oop->format($ts), "\n";
$dt = new DateTimeImmutable('@' . $ts);
echo 'dt=', $oop->format($dt), "\n";
echo 'empty=', var_export($oop->format([]), true), "\n";
$partial = ['tm_year' => 124, 'tm_mon' => 6, 'tm_mday' => 24, 'tm_hour' => 12, 'tm_min' => 0, 'tm_sec' => 0];
echo 'partial=', $oop->format($partial), "\n";
?>
--EXPECT--
oop=2024-07-24
proc=2024-07-24
ts=2024-07-24
dt=2024-07-24
empty=false
partial=2024-07-24
