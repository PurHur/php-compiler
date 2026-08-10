<?php

declare(strict_types=1);

$d = new DateTime('@0');

try {
    date_date_set($d, null, 1, 1);
    echo "fail:date_date_set\n";
} catch (TypeError $e) {
    echo 'ok:date_date_set:', $e->getMessage(), "\n";
}

try {
    date_time_set($d, null, 0);
    echo "fail:date_time_set\n";
} catch (TypeError $e) {
    echo 'ok:date_time_set:', $e->getMessage(), "\n";
}
