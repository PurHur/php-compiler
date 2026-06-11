<?php

declare(strict_types=1);

$day = gmmktime(0, 0, 0, 6, 21, 2026);
$rise = date_sunrise($day, SUNFUNCS_RET_TIMESTAMP, 51.5, -0.1, 90, 0);
$set = date_sunset($day, SUNFUNCS_RET_TIMESTAMP, 51.5, -0.1, 90, 0);
echo is_int($rise) || is_float($rise) ? "rise_ok\n" : "rise_fail\n";
echo is_int($set) || is_float($set) ? "set_ok\n" : "set_fail\n";
echo $rise, "\n";
echo $set, "\n";
