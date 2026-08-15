--TEST--
AOT parse_ini_string/file null process_sections/scanner_mode under strict_types TypeError (#31264)
--FILE--
<?php
declare(strict_types=1);
try {
    parse_ini_string('a=1', null);
    echo "fail sections\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    parse_ini_string('a=1', false, null);
    echo "fail scanner\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$f = sys_get_temp_dir().'/php_compiler_31264_parse_ini_aot.ini';
file_put_contents($f, "a=1\n");
try {
    parse_ini_file($f, null);
    echo "fail file sections\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    parse_ini_file($f, false, null);
    echo "fail file scanner\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
@unlink($f);
--EXPECT--
parse_ini_string(): Argument #2 ($process_sections) must be of type bool, null given
parse_ini_string(): Argument #3 ($scanner_mode) must be of type int, null given
parse_ini_file(): Argument #2 ($process_sections) must be of type bool, null given
parse_ini_file(): Argument #3 ($scanner_mode) must be of type int, null given
