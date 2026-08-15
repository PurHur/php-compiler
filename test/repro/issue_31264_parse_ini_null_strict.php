<?php
declare(strict_types=1);
// #31264 — parse_ini_string/file null typed args under strict_types → TypeError
// (ext/standard/basic_functions.c / basic_functions.stub.php)
try {
    var_export(parse_ini_string('a=1', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(parse_ini_string('a=1', false, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$f = sys_get_temp_dir().'/php_compiler_31264_parse_ini.ini';
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
