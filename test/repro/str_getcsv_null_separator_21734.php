<?php

declare(strict_types=0);

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    echo "DEP: $msg\n";

    return true;
});

$row = str_getcsv('a,b', null);
echo var_export($row, true), "\n";
