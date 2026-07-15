<?php

declare(strict_types=1);

$tz = new DateTimeZone('Europe/London');
$dt = new DateTime('2020-06-21 12:00:00', $tz);
echo 'offset=', $dt->getOffset(), PHP_EOL;
