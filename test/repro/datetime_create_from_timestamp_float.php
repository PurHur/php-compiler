<?php

declare(strict_types=1);

date_default_timezone_set('UTC');
$dt = DateTime::createFromTimestamp(1_700_000_000.5);
echo $dt->format('U.u'), "\n";
$di = DateTimeImmutable::createFromTimestamp(1_700_000_000.5);
echo $di->format('U.u'), "\n";
