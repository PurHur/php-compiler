--TEST--
stdlib date_create/date_create_immutable — procedural factories (#4124)
--FILE--
<?php
foreach (['date_create', 'date_create_immutable'] as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";

$dt = date_create('2026-06-01', new DateTimeZone('UTC'));
echo $dt->format('Y-m-d'), "\n";

$di = date_create_immutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));
echo $di->format('Y-m-d H:i:s'), "\n";

var_export(date_create('not-a-date'));
echo "\n";

$now = date_create();
echo $now instanceof DateTime ? "now\n" : "bad\n";
?>
--EXPECT--
11
2026-06-01
2026-06-01 12:00:00
false
now
