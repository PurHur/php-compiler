<?php
// #36221 program: timezone convert + format tokens (fixed instants)
date_default_timezone_set('UTC');
$utc = new DateTimeImmutable('2024-06-15 18:30:45', new DateTimeZone('UTC'));
$ny = $utc->setTimezone(new DateTimeZone('America/New_York'));
$tokyo = $utc->setTimezone(new DateTimeZone('Asia/Tokyo'));
$lines = [
    'utc=' . $utc->format('Y-m-d H:i:s P'),
    'ny=' . $ny->format('Y-m-d H:i:s T'),
    'tokyo=' . $tokyo->format('Y-m-d H:i:s T'),
    'atom=' . $utc->format(DateTimeInterface::ATOM),
    'offset_ny=' . $ny->getOffset(),
    'offset_tokyo=' . $tokyo->getOffset(),
];
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
