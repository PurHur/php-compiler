<?php

declare(strict_types=1);

$original = new DateTimeImmutable('2024-01-01 12:00:00', new DateTimeZone('UTC'));
$updated = $original->setTimezone(new DateTimeZone('America/New_York'));
echo get_class($updated), "\n";
echo ($updated === $original) ? "same\n" : "diff\n";
echo $original->format('Y-m-d H:i:s T'), "\n";
echo $updated->format('Y-m-d H:i:s T'), "\n";
$mutable = new DateTime('2024-01-01 12:00:00', new DateTimeZone('UTC'));
$m2 = $mutable->setTimezone(new DateTimeZone('America/New_York'));
echo ($m2 === $mutable) ? "mutable_same\n" : "mutable_diff\n";
echo $mutable->format('Y-m-d H:i:s T'), "\n";
