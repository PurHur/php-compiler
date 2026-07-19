<?php
// Repro for #20813 — IntlDateFormatter::formatObject / datefmt_format_object
$dt = new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'));
echo 'method=', method_exists(IntlDateFormatter::class, 'formatObject') ? 'yes' : 'no', "\n";
echo 'fn=', function_exists('datefmt_format_object') ? 'yes' : 'no', "\n";
echo 'arr=', IntlDateFormatter::formatObject($dt, [IntlDateFormatter::SHORT, IntlDateFormatter::NONE], 'en_US'), "\n";
echo 'proc=', datefmt_format_object($dt, [IntlDateFormatter::SHORT, IntlDateFormatter::NONE], 'en_US'), "\n";
echo 'pat=', IntlDateFormatter::formatObject($dt, 'yyyy-MM-dd', 'en_US'), "\n";
$cal = IntlCalendar::createInstance('UTC', 'en_US');
$cal->setTime(1705320000000.0);
echo 'cal=', IntlDateFormatter::formatObject($cal, [IntlDateFormatter::SHORT, IntlDateFormatter::NONE], 'en_US'), "\n";
