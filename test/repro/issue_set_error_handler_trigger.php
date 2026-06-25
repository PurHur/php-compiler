<?php
declare(strict_types=1);

$hits = 0;
set_error_handler(static function (int $no, string $str) use (&$hits): bool {
    echo "handler:$no\n";
    $hits++;
    return true;
});
trigger_error('probe', E_USER_WARNING);
echo "done hits=$hits\n";
