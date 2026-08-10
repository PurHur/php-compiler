<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$a = [3, 1, 2];
$r = sort($a, null);
echo var_export($r, true), "\n";
echo implode(',', $a), "\n";
foreach (['rsort', 'asort', 'arsort', 'ksort', 'krsort'] as $fn) {
    $b = [3, 1, 2];
    $r = $fn($b, null);
    echo "$fn:", var_export($r, true), ':', implode(',', $b), "\n";
}
