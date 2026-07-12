<?php

declare(strict_types=1);

// Maintainer gap: scandir/opendir on php://filter wrapper errors (#18418).
$path = 'php://filter/read=string.rot13/resource=/etc/passwd';

$warnings = [];
set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
    $warnings[] = $errstr;

    return true;
});

@scandir($path);
echo 'scandir_warnings='.count($warnings)."\n";
foreach ($warnings as $warning) {
    echo $warning."\n";
}

$warnings = [];
@opendir($path);
echo 'opendir_warnings='.count($warnings)."\n";
foreach ($warnings as $warning) {
    echo $warning."\n";
}
