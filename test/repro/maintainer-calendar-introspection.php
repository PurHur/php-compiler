<?php

declare(strict_types=1);

$info = cal_info(CAL_GREGORIAN);
echo $info['months'][1], "\n";
$parts = cal_from_jd(gregoriantojd(6, 7, 2026), CAL_GREGORIAN);
echo $parts['month'], '/', $parts['day'], "\n";
echo easter_days(2024), "\n";
$jd = gregoriantojd(6, 7, 2026);
echo jdmonthname($jd, CAL_MONTH_GREGORIAN_LONG), "\n";
echo jddayofweek($jd, CAL_DOW_SHORT), "\n";
