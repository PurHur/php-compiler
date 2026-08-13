--TEST--
JIT: timezone_abbreviations_list + DateTimeZone::listAbbreviations (#30780)
--FILE--
<?php
$a = timezone_abbreviations_list();
echo gettype($a), ' count=', is_array($a) ? (string) count($a) : 'na', "\n";
echo isset($a['utc']) ? 'has utc' : 'no utc', "\n";
$b = DateTimeZone::listAbbreviations();
echo gettype($b), ' count=', is_array($b) ? (string) count($b) : 'na', "\n";
echo isset($b['utc']) ? 'has utc' : 'no utc', "\n";
echo "ok\n";
?>
--EXPECT--
array count=144
has utc
array count=144
has utc
ok
