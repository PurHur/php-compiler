--TEST--
cal_info(-1) all-calendars + Reflection default -1 (#28907, ext/calendar/calendar.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('cal_info');
$p = $r->getParameters()[0];
echo 'name=', $p->getName(), "\n";
echo 'default=', var_export($p->getDefaultValue(), true), "\n";

$all = cal_info(-1);
echo 'm1_count=', count($all), "\n";
echo 'm1_keys=', implode(',', array_keys($all)), "\n";
echo 'm1_g=', $all[0]['calname'], "\n";

$none = cal_info();
echo 'none_count=', count($none), "\n";
echo 'same_shape=', (int) (array_keys($all) === array_keys($none)), "\n";

$cal = -1;
$dyn = cal_info($cal);
echo 'dyn_count=', count($dyn), "\n";

try {
    $_ = cal_info(-2);
    echo "bad_neg\n";
} catch (ValueError $e) {
    echo "invalid_neg\n";
}
?>
--EXPECT--
name=calendar
default=-1
m1_count=4
m1_keys=0,1,2,3
m1_g=Gregorian
none_count=4
same_shape=1
dyn_count=4
invalid_neg
