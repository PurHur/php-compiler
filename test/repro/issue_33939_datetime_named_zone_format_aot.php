<?php

/**
 * Repro #33939 — named-zone DateTime format/getOffset/setTimezone under AOT.
 *
 *   php test/repro/issue_33939_datetime_named_zone_format_aot.php
 *   php bin/compile.php -o /tmp/x test/repro/issue_33939_datetime_named_zone_format_aot.php && /tmp/x
 */
$ny = new DateTime('2024-01-15 12:00:00', new DateTimeZone('America/New_York'));
echo 'wall=', $ny->format('Y-m-d H:i:s'), "\n";
echo 'T=', $ny->format('T'), "\n";
echo 'e=', $ny->format('e'), "\n";
echo 'O=', $ny->format('O'), "\n";
echo 'P=', $ny->format('P'), "\n";
echo 'offset=', $ny->getOffset(), "\n";

$utc = new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'));
$utc->setTimezone(new DateTimeZone('America/New_York'));
echo 'setz=', $utc->format('Y-m-d H:i:s T'), "\n";
echo 'setz_off=', $utc->getOffset(), "\n";
