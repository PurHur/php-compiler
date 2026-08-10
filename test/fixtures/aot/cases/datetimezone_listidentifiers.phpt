--TEST--
AOT: DateTimeZone::listIdentifiers(DateTimeZone::UTC) (#29735)
--FILE--
<?php
$a = DateTimeZone::listIdentifiers(DateTimeZone::UTC);
echo 'ok:', (is_array($a) && count($a) === 1 && $a[0] === 'UTC') ? '1' : '0', "\n";
echo 'type=', gettype($a), "\n";
$n = count(DateTimeZone::listIdentifiers(DateTimeZone::AFRICA));
echo 'africa:', $n > 0 ? '1' : '0', "\n";
--EXPECT--
ok:1
type=array
africa:1
