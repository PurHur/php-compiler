<?php

declare(strict_types=1);

echo 'x';
$ok = header('Y: z');
var_export(false === $ok);
echo "\n";
$e = error_get_last();
var_export($e !== null && str_contains($e['message'] ?? '', 'Cannot modify header information'));
echo "\n";
