<?php
// Repro for #6151 — IntlCalendar / IntlTimeZone v1
var_export(class_exists('IntlCalendar'));
echo "\n";
var_export(class_exists('IntlTimeZone'));
echo "\n";
$cal = IntlCalendar::createInstance('UTC', 'en_US');
$cal->setTime(1579096800000.0); // 2020-01-15 14:00:00 UTC
echo $cal->get(IntlCalendar::FIELD_YEAR), "\n";
echo $cal->getTimeZone()->getID(), "\n";
