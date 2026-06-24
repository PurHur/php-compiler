<?php
declare(strict_types=1);

$path = '/nope/phpc-warn-'.getmypid();
$info = [];
set_error_handler(static function (int $s, string $m, string $f, int $l) use (&$info): bool {
    $info = ['severity' => $s, 'file' => $f, 'line' => $l];

    return true;
});
file_get_contents($path);
echo 'file=', $info['file'] ?? '', "\n";
echo 'line=', $info['line'] ?? -1, "\n";
