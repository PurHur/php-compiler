<?php
// Repro #30780 — timezone_abbreviations_list + DateTimeZone::listAbbreviations AOT.
$a = timezone_abbreviations_list();
echo gettype($a), ' count=', is_array($a) ? count($a) : 'na', "\n";
echo isset($a['utc']) ? 'has utc' : 'no utc', "\n";
$b = DateTimeZone::listAbbreviations();
echo gettype($b), ' count=', is_array($b) ? count($b) : 'na', "\n";
echo isset($b['utc']) ? 'has utc' : 'no utc', "\n";
echo "ok\n";
