<?php
// Repro for #21097 — IntlDateFormatter::__construct must match create()
$ts = 1592179200;
$a = new IntlDateFormatter('en_US', IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
$b = IntlDateFormatter::create('en_US', IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
echo 'new_format=';
var_export($a->format($ts));
echo "\n";
echo 'create_format=';
var_export($b->format($ts));
echo "\n";
echo 'new_pattern=';
var_export($a->getPattern());
echo "\n";
echo 'create_pattern=';
var_export($b->getPattern());
echo "\n";
$c = new IntlDateFormatter('en_US', IntlDateFormatter::FULL, IntlDateFormatter::FULL, null, null, 'yyyy-MM-dd');
echo 'pattern_ctor_format=';
var_export($c->format(0));
echo "\n";
echo 'pattern_ctor_get=';
var_export($c->getPattern());
echo "\n";
