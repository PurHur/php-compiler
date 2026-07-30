--TEST--
IntlDateFormatter parse/localtime/timezone/error surface (#20729)
--FILE--
<?php
$f = IntlDateFormatter::create('en_US', -1, -1, 'UTC', IntlDateFormatter::GREGORIAN, 'yyyy-MM-dd');
echo 'parse=', $f->parse('2024-06-15'), "\n";
echo 'format=', $f->format(1718409600), "\n";

$lt = $f->localtime('2024-06-15');
echo 'y=', $lt['tm_year'] + 1900, ' m=', $lt['tm_mon'] + 1, ' d=', $lt['tm_mday'], "\n";
// Date-only pattern: unset clock fields follow wall clock (#25228), not forced 0.
echo 'yday=', $lt['tm_yday'], "\n";

echo 'bad_prefix=', var_export($f->parse('xx2024-06-15'), true), "\n";
$pos = 2;
echo 'from_offset=', $f->parse('xx2024-06-15', $pos), "\n";

echo 'bad=', var_export($f->parse('nope'), true), "\n";
echo 'code=', $f->getErrorCode(), "\n";
echo 'msg=', $f->getErrorMessage(), "\n";

echo 'tz=', $f->getTimeZone()->getID(), "\n";
$f->setTimeZone('America/New_York');
echo 'tz2=', $f->getTimeZone()->getID(), "\n";

$f2 = IntlDateFormatter::create('en_US', -1, -1, 'UTC', 1, 'yyyy-MM-dd HH:mm:ss');
echo 'dt=', $f2->parse('2024-06-15 12:34:56'), "\n";
echo 'ptc=', (int) method_exists($f, 'parseToCalendar'), "\n";
?>
--EXPECT--
parse=1718409600
format=2024-06-15
y=2024 m=6 d=15
yday=167
bad_prefix=false
from_offset=1718409600
bad=false
code=9
msg=Date parsing failed: U_PARSE_ERROR
tz=UTC
tz2=America/New_York
dt=1718454896
ptc=0
