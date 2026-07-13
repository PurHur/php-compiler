<?php
declare(strict_types=1);

$row = str_getcsv('"');
$count = count($row);
$len = strlen($row[0]);
$ord0 = ord($row[0]);

echo 'count=', $count, ' len=', $len, ' ord0=', $ord0, "\n";
if (1 !== $count || 1 !== $len || 0 !== $ord0) {
    exit(1);
}
