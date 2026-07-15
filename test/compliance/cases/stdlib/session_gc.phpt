--TEST--
Stdlib: session_gc() purges expired session files (#6006)
--FILE--
<?php
ob_start();
echo (int) function_exists('session_gc'), "\n";
var_export(session_gc());
echo "\n";

$dir = sys_get_temp_dir().'/phpc_gc_'.getmypid();
@mkdir($dir, 0700, true);
putenv('PHP_COMPILER_SESSION_DIR='.$dir);

session_start();
var_export(session_gc());
echo "\n";
session_write_close();

$stale = $dir.'/sess_deadbeef';
file_put_contents($stale, 'x');
touch($stale, time() - 9999);
session_start();
var_export(session_gc());
echo "\n";
--EXPECT--
PHP Warning:  session_gc(): Session cannot be garbage collected when there is no active session in - on line 4
1
false
0
0
