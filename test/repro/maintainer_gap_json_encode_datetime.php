<?php
declare(strict_types=1);

$dt = new DateTime('2020-01-01 00:00:00', new DateTimeZone('UTC'));
echo json_encode($dt), "\n";
echo json_encode(new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'))), "\n";
echo json_encode(new DateTimeZone('UTC')), "\n";
