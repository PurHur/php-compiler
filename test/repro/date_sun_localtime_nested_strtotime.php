<?php

declare(strict_types=1);

$ts = strtotime('2026-07-11');
echo 'var_localtime: '.json_encode(localtime($ts, true)['tm_mday'] ?? null)."\n";
echo 'nested_localtime: '.json_encode(localtime(strtotime('2026-07-11'), true)['tm_mday'] ?? null)."\n";

$ts2 = strtotime('2026-07-11');
echo 'var_sunrise: '.json_encode(date_sunrise($ts2, SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1))."\n";
echo 'nested_sunrise: '.json_encode(date_sunrise(strtotime('2026-07-11'), SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1))."\n";

$ts3 = strtotime('2026-07-11');
echo 'var_sunset: '.json_encode(date_sunset($ts3, SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1))."\n";
echo 'nested_sunset: '.json_encode(date_sunset(strtotime('2026-07-11'), SUNFUNCS_RET_STRING, 40.7, -74.0, 90, 1))."\n";
