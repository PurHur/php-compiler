--TEST--
date DateInterval from_string/date_string direct prop Undefined; cast keeps wire (#24334, ext/date/php_date.c)
--FILE--
<?php
$j = DateInterval::createFromDateString('last day of next month');
$n = new DateInterval('P1D');
set_error_handler(static function ($no, $s) {
    echo 'warn:', $s, "\n";

    return true;
});
$v = $j->from_string;
echo 'from_string=', var_export($v, true), ' isset=', var_export(isset($j->from_string), true), "\n";
$ds = $j->date_string;
echo 'date_string=', var_export($ds, true), ' isset_ds=', var_export(isset($j->date_string), true), "\n";
echo 'pexists=', var_export(property_exists($j, 'from_string'), true), "\n";
$nv = $n->from_string;
echo 'p1d_from_string=', var_export($nv, true), ' isset=', var_export(isset($n->from_string), true), "\n";
restore_error_handler();
$cast = (array) $j;
echo 'cast_fs=', var_export($cast['from_string'] ?? null, true), ' cast_ds=', var_export($cast['date_string'] ?? null, true), "\n";
$castN = (array) $n;
echo 'p1d_cast_fs=', var_export($castN['from_string'] ?? null, true), "\n";
echo 'y=', var_export($j->y, true), ' isset_y=', var_export(isset($j->y), true), "\n";
--EXPECT--
warn:Undefined property: DateInterval::$from_string
from_string=NULL isset=false
warn:Undefined property: DateInterval::$date_string
date_string=NULL isset_ds=false
pexists=false
warn:Undefined property: DateInterval::$from_string
p1d_from_string=NULL isset=false
cast_fs=true cast_ds='last day of next month'
p1d_cast_fs=false
y=0 isset_y=true
