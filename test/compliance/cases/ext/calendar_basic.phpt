--TEST--
ext calendar cal_days_in_month/gregoriantojd/easter_date VM parity (issue #7223)
--FILE--
<?php
echo cal_days_in_month(CAL_GREGORIAN, 2, 2024), "\n";
echo cal_days_in_month(CAL_GREGORIAN, 2, 2023), "\n";
echo cal_days_in_month(CAL_JULIAN, 2, 2024), "\n";
echo cal_days_in_month(CAL_GREGORIAN, 4, 2024), "\n";
echo gregoriantojd(3, 15, 2024), "\n";
echo gregoriantojd(1, 1, 1970), "\n";
echo gregoriantojd(10, 4, 1582), "\n";
echo easter_date(2024), "\n";
echo easter_date(2000), "\n";
try {
    $_ = cal_days_in_month(99, 1, 2024);
    echo "bad\n";
} catch (ValueError $e) {
    echo "invalid_cal\n";
}
?>
--EXPECT--
29
28
29
30
2460385
2440588
2299150
1711843200
956448000
invalid_cal
