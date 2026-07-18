--TEST--
NumberFormatter create/format DECIMAL locale subset without ext/intl (#5154)
--FILE--
<?php
echo 'intl_loaded=', (int) extension_loaded('intl'), "\n";
echo 'formatter=', (int) class_exists('NumberFormatter', false), "\n";
echo 'calendar=', (int) class_exists('IntlCalendar', false), "\n";
echo 'collator=', (int) class_exists('Collator', false), "\n";

$en = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo $en->format(1234.5), "\n";

$de = NumberFormatter::create('de_DE', NumberFormatter::DECIMAL);
echo $de->format(1234.5), "\n";

$pct = NumberFormatter::create('en_US', NumberFormatter::PERCENT);
echo $pct->format(0.25), "\n";
?>
--EXPECT--
intl_loaded=1
formatter=1
calendar=1
collator=1
1,234.5
1.234,5
25%
