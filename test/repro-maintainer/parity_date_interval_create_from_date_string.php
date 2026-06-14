<?php

declare(strict_types=1);

$iv = date_interval_create_from_date_string('1 day');
var_export($iv instanceof DateInterval);
echo "\n";
if ($iv) {
    echo $iv->format('%d'), "\n";
}

$bad = date_interval_create_from_date_string('not an interval');
var_export($bad);
echo "\n";

try {
    date_interval_create_from_date_string([]);
} catch (Throwable $e) {
    echo 'array:', get_class($e), ':', $e->getMessage(), "\n";
}
