<?php
declare(strict_types=1);

$dt = new DateTime('2024-06-01 12:34:56.789012');
echo $dt->getMicrosecond(), "\n";
$dt->setMicrosecond(123456);
echo $dt->format('u'), "\n";

$immutable = DateTimeImmutable::createFromFormat('U.u', '1717242896.654321');
echo $immutable->getMicrosecond(), "\n";
