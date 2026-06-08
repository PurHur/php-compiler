--TEST--
posix_get_last_error()/posix_ctermid()/posix_getcwd() registered (issue #7175)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('posix_get_last_error') ? 'gle_yes' : 'gle_no', "\n";
echo function_exists('posix_ctermid') ? 'ctermid_yes' : 'ctermid_no', "\n";
echo function_exists('posix_getcwd') ? 'getcwd_yes' : 'getcwd_no', "\n";
echo posix_get_last_error(), "\n";
var_export(is_string(posix_ctermid()));
echo "\n";
$cwd = posix_getcwd();
var_export(is_string($cwd) && '' !== $cwd);
echo "\n";
--EXPECT--
gle_yes
ctermid_yes
getcwd_yes
0
true
true
