--TEST--
ext calendar introspection API VM parity (issue #7252)
--FILE--
<?php
$info = cal_info(CAL_GREGORIAN);
echo $info['months'][1], "\n";
echo $info['calname'], "\n";
echo $info['maxdaysinmonth'], "\n";
$jd = gregoriantojd(6, 7, 2026);
$parts = cal_from_jd($jd, CAL_GREGORIAN);
echo $parts['month'], '/', $parts['day'], "\n";
echo $parts['dayname'], "\n";
echo easter_days(2024), "\n";
echo jdmonthname($jd, CAL_MONTH_GREGORIAN_LONG), "\n";
echo jddayofweek($jd, CAL_DOW_SHORT), "\n";
echo jddayofweek($jd), "\n";
try {
    $_ = cal_info(99);
    echo "bad_cal\n";
} catch (ValueError $e) {
    echo "invalid_cal\n";
}
?>
--EXPECT--
January
Gregorian
31
6/7
Sunday
10
June
Sun
0
invalid_cal
