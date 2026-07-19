--TEST--
IntlDateFormatter::formatObject / datefmt_format_object (#20813)
--FILE--
<?php
$dt = new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'));
echo 'arr=', IntlDateFormatter::formatObject($dt, [IntlDateFormatter::SHORT, IntlDateFormatter::NONE], 'en_US'), "\n";
echo 'proc=', datefmt_format_object($dt, [IntlDateFormatter::SHORT, IntlDateFormatter::NONE], 'en_US'), "\n";
echo 'pat=', IntlDateFormatter::formatObject($dt, 'yyyy-MM-dd', 'en_US'), "\n";
echo 'fn=', function_exists('datefmt_format_object') ? 'yes' : 'no', "\n";
?>
--EXPECT--
arr=1/15/24
proc=1/15/24
pat=2024-01-15
fn=yes
