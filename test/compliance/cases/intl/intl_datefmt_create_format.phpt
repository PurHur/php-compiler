--TEST--
datefmt_create/format/set_pattern/timezone procedurals (#20837)
--FILE--
<?php
$f = datefmt_create('en_US', IntlDateFormatter::SHORT, IntlDateFormatter::NONE, 'UTC');
echo 'create=', ($f instanceof IntlDateFormatter) ? 'yes' : 'no', "\n";
echo 'proc_format=', datefmt_format($f, 0), "\n";
$oop = IntlDateFormatter::create('en_US', IntlDateFormatter::SHORT, IntlDateFormatter::NONE, 'UTC');
echo 'oop_format=', $oop->format(0), "\n";
echo 'pat_before=', datefmt_get_pattern($f), "\n";
echo 'set=', datefmt_set_pattern($f, 'yyyy-MM-dd') ? 'yes' : 'no', "\n";
echo 'pat_after=', datefmt_get_pattern($f), "\n";
echo 'fmt_pat=', datefmt_format($f, 0), "\n";
$tz = datefmt_get_timezone($f);
echo 'tz=', ($tz instanceof IntlTimeZone) ? $tz->getID() : 'no', "\n";
echo 'setz=', datefmt_set_timezone($f, 'America/New_York') ? 'yes' : 'no', "\n";
$tz2 = datefmt_get_timezone($f);
echo 'tz2=', ($tz2 instanceof IntlTimeZone) ? $tz2->getID() : 'no', "\n";
foreach (['datefmt_create','datefmt_format','datefmt_set_pattern','datefmt_get_timezone'] as $fn) {
  echo "$fn=", function_exists($fn) ? 'yes' : 'no', "\n";
}
?>
--EXPECT--
create=yes
proc_format=1/1/70
oop_format=1/1/70
pat_before=M/d/yy
set=yes
pat_after=yyyy-MM-dd
fmt_pat=1970-01-01
tz=UTC
setz=yes
tz2=America/New_York
datefmt_create=yes
datefmt_format=yes
datefmt_set_pattern=yes
datefmt_get_timezone=yes
