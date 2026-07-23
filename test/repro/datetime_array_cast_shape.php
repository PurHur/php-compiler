<?php
declare(strict_types=1);

$d = new DateTime('2020-01-01 12:30:45', new DateTimeZone('America/New_York'));
$a = (array) $d;
foreach (array_keys($a) as $k) {
    if (str_starts_with((string) $k, '__dt_')) {
        fwrite(STDERR, "leaked internal key: {$k}\n");
        exit(1);
    }
}
var_export($a);
echo "\n";

$z = new DateTimeZone('UTC');
var_export((array) $z);
echo "\n";

$i = new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC'));
var_export((array) $i);
echo "\n";
