<?php
declare(strict_types=1);
// #31264 — parse_ini_file null $process_sections / $scanner_mode under strict_types → TypeError
$f = tempnam(sys_get_temp_dir(), 'ini');
file_put_contents($f, "a=1\n");
try {
    var_export(parse_ini_file($f, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(parse_ini_file($f, false, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
@unlink($f);
