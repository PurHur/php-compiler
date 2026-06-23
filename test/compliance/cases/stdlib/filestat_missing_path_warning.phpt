--TEST--
stdlib fileatime()/filectime()/fileinode()/fileowner()/filegroup()/fileperms() stat failed warning (#10837)
--FILE--
<?php
$path = '/nope/stat_warning_test';
$warnings = 0;
set_error_handler(static function () use (&$warnings): bool {
    ++$warnings;
    return true;
});
fileatime($path);
filectime($path);
fileinode($path);
fileowner($path);
filegroup($path);
fileperms($path);
restore_error_handler();
echo $warnings, "\n";
echo var_export(fileatime($path), true), "\n";
?>
--EXPECT--
6
false
