<?php

declare(strict_types=1);

$c = [];
$c['self'] = &$c;

$warnings = [];
set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
    $warnings[] = $errstr;

    return true;
});

$r = var_export($c, true);
restore_error_handler();

echo 'warn=', $warnings[0] ?? '', "\n";
echo 'len=', strlen($r), "\n";
echo 'out=', $r, "\n";
