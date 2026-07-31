--TEST--
stdlib session_decode() merges into existing $_SESSION (#26088, ext/session/mod_php.c)
--FILE--
<?php
ini_set('session.use_cookies', '0');
$dir = sys_get_temp_dir().'/phpc_26088c_'.getmypid();
@mkdir($dir);
ini_set('session.save_path', $dir);
session_id('compliance26088mergeid01234');
session_start();
$_SESSION = ['a' => 1, 'keep' => 'yes'];
$ok = session_decode('b|i:2;a|i:99;');
$merged = $_SESSION;
ksort($merged);
session_write_close();
$_SESSION = ['stale' => 9];
session_id('compliance26088mergeid01234');
session_start();
$loaded = $_SESSION;
ksort($loaded);
var_export($ok);
echo "\n", json_encode($merged), "\n", json_encode($loaded), "\n";
--EXPECT--
true
{"a":99,"b":2,"keep":"yes"}
{"a":99,"b":2,"keep":"yes"}
