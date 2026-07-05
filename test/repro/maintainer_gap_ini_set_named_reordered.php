<?php

declare(strict_types=1);

ini_set(value: 'Europe/London', option: 'date.timezone');
$tz = ini_get('date.timezone');
echo 'after=', $tz, "\n";
exit('Europe/London' === $tz ? 0 : 1);
