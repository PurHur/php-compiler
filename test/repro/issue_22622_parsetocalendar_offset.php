<?php
// #22622 — parseToCalendar must write back &$offset like parse()
$fmt = new IntlDateFormatter('en_US', IntlDateFormatter::SHORT, IntlDateFormatter::NONE, 'UTC');
$s = '1/15/20';
$off = 0;
$ts = $fmt->parse($s, $off);
echo "parse ts=$ts off=$off\n";
$off = 0;
$ts = $fmt->parseToCalendar($s, $off);
echo "ptc ts=$ts off=$off\n";
$s2 = '1/15/20 leftover';
$off = 0;
$ts = $fmt->parse($s2, $off);
echo "parse_partial ts=$ts off=$off\n";
$off = 0;
$ts = $fmt->parseToCalendar($s2, $off);
echo "ptc_partial ts=$ts off=$off\n";
