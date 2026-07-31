--TEST--
stdlib session.serialize_handler=php_serialize encode/decode (#26089, ext/session/session.c)
--FILE--
<?php
ini_set('session.use_cookies', '0');
$dir = sys_get_temp_dir().'/phpc_26089c_'.getmypid();
@mkdir($dir);
ini_set('session.save_path', $dir);
ini_set('session.serialize_handler', 'php_serialize');
session_id('compliance26089phpserialize0');
session_start();
$_SESSION = ['a' => 1, 'b' => 'x'];
$enc = session_encode();
session_decode(serialize(['c' => 3]));
$after = $_SESSION;
ksort($after);
session_write_close();
session_id('compliance26089phpserialize0');
session_start();
$loaded = $_SESSION;
ksort($loaded);
echo $enc, "\n";
echo json_encode($after), "\n";
echo json_encode($loaded), "\n";
--EXPECT--
a:2:{s:1:"a";i:1;s:1:"b";s:1:"x";}
{"c":3}
{"c":3}
