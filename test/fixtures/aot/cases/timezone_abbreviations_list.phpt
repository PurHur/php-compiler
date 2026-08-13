--TEST--
AOT: timezone_abbreviations_list + DateTimeZone::listAbbreviations (#30780)
--FILE--
<?php
$a = timezone_abbreviations_list();
echo gettype($a), ' count=', is_array($a) ? (string) count($a) : 'na', "\n";
echo isset($a['utc']) ? 'has utc' : 'no utc', "\n";
$b = DateTimeZone::listAbbreviations();
echo gettype($b), ' count=', is_array($b) ? (string) count($b) : 'na', "\n";
echo isset($b['utc']) ? 'has utc' : 'no utc', "\n";
echo 'same=', ($a === $b || (is_array($a) && is_array($b) && count($a) === count($b) && isset($a['utc'], $b['utc']))) ? 'yes' : 'no', "\n";
echo "ok\n";
?>
--EXPECT--
array count=144
has utc
array count=144
has utc
same=yes
ok
