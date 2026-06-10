<?php

$mutable = new DateTime('2020-06-15 10:30:00', new DateTimeZone('UTC'));
$immutable = DateTimeImmutable::createFromMutable($mutable);
echo $immutable->format('Y'), "\n";
