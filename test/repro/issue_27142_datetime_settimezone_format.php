<?php
/**
 * Repro #27142 — setTimezone keeps instant; format must show local wall-clock.
 *
 *   php bin/vm.php test/repro/issue_27142_datetime_settimezone_format.php
 *   php bin/jit.php test/repro/issue_27142_datetime_settimezone_format.php
 */
$d = new DateTime('2024-01-01 12:00:00', new DateTimeZone('UTC'));
$d->setTimezone(new DateTimeZone('America/New_York'));
echo $d->getOffset(), "\n";
echo $d->format('Y-m-d H:i:s T'), "\n";
$i = (new DateTimeImmutable('2024-01-01 12:00:00', new DateTimeZone('UTC')))
    ->setTimezone(new DateTimeZone('America/New_York'));
echo $i->format('Y-m-d H:i:s T'), "\n";
$ny = new DateTime('2020-06-01 12:00:00', new DateTimeZone('America/New_York'));
echo $ny->format('r'), "\n";
