--TEST--
stdlib session.serialize_handler=php_binary encode/decode (#26090, ext/session/session.c)
--FILE--
<?php
ini_set('session.use_cookies', '0');
$dir = sys_get_temp_dir().'/phpc_26090c_'.getmypid();
@mkdir($dir);
ini_set('session.save_path', $dir);
ini_set('session.serialize_handler', 'php_binary');
session_id('compliance26090phpbinary012');
session_start();
$_SESSION = ['a' => 1, 'bb' => 'xy'];
echo bin2hex(session_encode()), "\n";
session_decode("\x01".'a'.'i:99;'."\x02".'bb'.'s:2:"ZZ";');
$after = $_SESSION;
ksort($after);
session_write_close();
session_id('compliance26090phpbinary012');
session_start();
$loaded = $_SESSION;
ksort($loaded);
echo json_encode($after), "\n";
echo json_encode($loaded), "\n";
--EXPECT--
0161693a313b026262733a323a227879223b
{"a":99,"bb":"ZZ"}
{"a":99,"bb":"ZZ"}
