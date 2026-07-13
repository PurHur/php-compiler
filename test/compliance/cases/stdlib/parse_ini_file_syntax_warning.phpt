--TEST--
stdlib parse_ini_file() syntax failure emits Warning with file path (#18544, ext/standard/ini.c)
--FILE--
<?php
$tmp = tempnam(sys_get_temp_dir(), 'ini');
file_put_contents($tmp, "on=1\noff=0\n");
$warnings = 0;
set_error_handler(static function () use (&$warnings): bool {
    ++$warnings;
    return true;
});
$result = parse_ini_file($tmp);
restore_error_handler();
unlink($tmp);
var_export($result);
echo "\n";
echo $warnings, "\n";
?>
--EXPECT--
false
1
