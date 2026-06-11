<?php
date_default_timezone_set('UTC');
$dt = DateTime::createFromTimestamp(1700000000);
echo $dt->getTimestamp(), "\n";
$di = DateTimeImmutable::createFromTimestamp(1700000000);
echo $di->getTimestamp(), "\n";
