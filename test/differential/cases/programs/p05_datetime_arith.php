<?php
// #36221 program: DateTime arithmetic across DST boundary (fixed TZ)
date_default_timezone_set('America/New_York');
$start = new DateTimeImmutable('2024-03-09 12:00:00');
$cross = $start->modify('+24 hours');
$winter = new DateTimeImmutable('2024-11-02 12:00:00');
$winter2 = $winter->modify('+24 hours');
$fmt = static function (DateTimeImmutable $d): string {
    return $d->format('Y-m-d H:i:s T');
};
$diffSpring = $start->diff($cross);
$diffWinter = $winter->diff($winter2);
$lines = [
    'spring_from=' . $fmt($start),
    'spring_to=' . $fmt($cross),
    'spring_hours=' . (($cross->getTimestamp() - $start->getTimestamp()) / 3600),
    'spring_days=' . $diffSpring->days . ':h=' . $diffSpring->h,
    'winter_from=' . $fmt($winter),
    'winter_to=' . $fmt($winter2),
    'winter_hours=' . (($winter2->getTimestamp() - $winter->getTimestamp()) / 3600),
    'winter_days=' . $diffWinter->days . ':h=' . $diffWinter->h,
];
$interval = new DateInterval('P1DT2H');
$rolled = $start->add($interval);
$lines[] = 'rolled=' . $fmt($rolled);
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
