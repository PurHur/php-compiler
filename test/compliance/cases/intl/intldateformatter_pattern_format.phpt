--TEST--
IntlDateFormatter create/format ICU pattern subset without ext/intl (#19549)
--FILE--
<?php
echo 'intl_loaded=', (int) extension_loaded('intl'), "\n";
echo 'formatter=', (int) class_exists('IntlDateFormatter', false), "\n";
echo 'collator=', (int) class_exists('Collator', false), "\n";

$f = IntlDateFormatter::create(
    'en_US',
    IntlDateFormatter::NONE,
    IntlDateFormatter::NONE,
    'UTC',
    IntlDateFormatter::GREGORIAN,
    'yyyy-MM-dd'
);
$dt = new DateTime('2024-03-15 12:34:56', new DateTimeZone('UTC'));
echo $f->format($dt), "\n";

$f2 = IntlDateFormatter::create('en_US', -1, -1, 'UTC', 1, 'yyyy-MM-dd HH:mm:ss');
echo $f2->format($dt), "\n";

$f3 = IntlDateFormatter::create('en_US', -1, -1, 'America/New_York', 1, 'yyyy-MM-dd HH:mm:ss');
echo $f3->format($dt), "\n";
?>
--EXPECT--
intl_loaded=0
formatter=1
collator=0
2024-03-15
2024-03-15 12:34:56
2024-03-15 08:34:56
