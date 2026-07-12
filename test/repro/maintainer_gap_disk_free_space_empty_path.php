<?php

declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;

    return true;
});

$free = disk_free_space('');
$total = disk_total_space('');

echo 'free=', var_export($free, true), "\n";
echo 'total=', var_export($total, true), "\n";
echo 'warnings=', count($warnings), "\n";
