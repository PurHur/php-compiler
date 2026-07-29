--TEST--
ext calendar JD Reflection names + named args (VM, issue #24362)
--FILE--
<?php
foreach (['cal_from_jd', 'easter_days', 'jdtogregorian', 'jdtojulian', 'jdtofrench', 'jdtojewish', 'jddayofweek', 'jdmonthname'] as $f) {
    $r = new ReflectionFunction($f);
    $bits = [];
    foreach ($r->getParameters() as $p) {
        $bits[] = $p->getName() . ($p->isOptional() ? '=' : '');
    }
    echo $f, ':', implode(',', $bits), PHP_EOL;
}
$a = cal_from_jd(julian_day: 2451545, calendar: CAL_GREGORIAN);
echo 'cal=', $a['month'], '/', $a['day'], '/', $a['year'], PHP_EOL;
echo 'easter_days=', easter_days(year: 2020, mode: CAL_EASTER_DEFAULT), PHP_EOL;
echo 'jewish=', jdtojewish(julian_day: 2451545), PHP_EOL;
try {
    easter_days(year: 2020, method: CAL_EASTER_DEFAULT);
    echo "legacy method accepted\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter') ? "legacy method rejected\n" : "legacy method other\n";
}
?>
--EXPECT--
cal_from_jd:julian_day,calendar
easter_days:year=,mode=
jdtogregorian:julian_day
jdtojulian:julian_day
jdtofrench:julian_day
jdtojewish:julian_day,hebrew=,flags=
jddayofweek:julian_day,mode=
jdmonthname:julian_day,mode
cal=1/1/2000
easter_days=22
jewish=4/23/5760
legacy method rejected
