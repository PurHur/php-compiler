<?php

declare(strict_types=1);

$tz = new DateTimeZone('UTC');
$dt = new DateTime('2020-01-01 12:00:00', $tz);
echo $dt->format('c'), "\n";
