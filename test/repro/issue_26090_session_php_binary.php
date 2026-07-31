<?php

/**
 * #26090 — session.serialize_handler=php_binary encode/decode/file round-trip.
 */
ini_set('session.use_cookies', '0');
$dir = sys_get_temp_dir().'/phpc_26090_'.getmypid();
@mkdir($dir);
ini_set('session.save_path', $dir);
ini_set('session.serialize_handler', 'php_binary');

session_id('issue26090phpbinaryid01234');
session_start();
$_SESSION = ['a' => 1, 'bb' => 'xy'];
$enc = session_encode();
$line1 = 'enc_hex='.bin2hex($enc);

session_decode("\x01".'a'.'i:99;'."\x02".'bb'.'s:2:"ZZ";');
$after = $_SESSION;
ksort($after);
$line2 = 'after='.json_encode($after);

session_write_close();
session_id('issue26090phpbinaryid01234');
session_start();
$loaded = $_SESSION;
ksort($loaded);

echo $line1, "\n", $line2, "\n", 'reload='.json_encode($loaded), "\n";
