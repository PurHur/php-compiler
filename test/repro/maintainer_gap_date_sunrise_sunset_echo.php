<?php

declare(strict_types=1);

$ts = gmmktime(12, 0, 0, 6, 21, 2020);
echo date_sunrise($ts, SUNFUNCS_RET_STRING, 40.7, -74.0), "\n";
echo date_sunset($ts, SUNFUNCS_RET_STRING, 40.7, -74.0), "\n";
echo date_sunrise($ts, SUNFUNCS_RET_TIMESTAMP, 40.7, -74.0), "\n";
echo date_sunset($ts, SUNFUNCS_RET_TIMESTAMP, 40.7, -74.0), "\n";
