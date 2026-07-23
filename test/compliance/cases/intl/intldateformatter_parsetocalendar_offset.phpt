--TEST--
IntlDateFormatter::parseToCalendar updates &$offset like parse (#22622)
--FILE--
<?php
$fmt = new IntlDateFormatter('en_US', IntlDateFormatter::SHORT, IntlDateFormatter::NONE, 'UTC');
$s = '1/15/20';
$off = 0;
$tsParse = $fmt->parse($s, $off);
$offParse = $off;
$off = 0;
$tsPtc = $fmt->parseToCalendar($s, $off);
$offPtc = $off;
echo 'match_ts=', (int) ($tsParse === $tsPtc), "\n";
echo 'match_off=', (int) ($offParse === $offPtc), "\n";
echo 'off=', $offPtc, "\n";

$s2 = '1/15/20 leftover';
$off = 0;
$fmt->parse($s2, $off);
$offParse = $off;
$off = 0;
$fmt->parseToCalendar($s2, $off);
echo 'partial_match=', (int) ($offParse === $off), "\n";
echo 'partial_off=', $off, "\n";
?>
--EXPECT--
match_ts=1
match_off=1
off=7
partial_match=1
partial_off=7
