<?php

/**
 * #26089 — session.serialize_handler=php_serialize encode/decode/file round-trip.
 */
ini_set('session.use_cookies', '0');
$dir = sys_get_temp_dir().'/phpc_26089_'.getmypid();
@mkdir($dir);
ini_set('session.save_path', $dir);
ini_set('session.serialize_handler', 'php_serialize');

session_id('issue26089phpserialize0123');
session_start();
$_SESSION = ['a' => 1, 'b' => 'x'];
$enc = session_encode();
$line1 = 'enc='.var_export($enc, true);

session_decode(serialize(['c' => 3]));
$after = $_SESSION;
ksort($after);
$line2 = 'after='.json_encode($after);

session_write_close();
session_id('issue26089phpserialize0123');
session_start();
$loaded = $_SESSION;
ksort($loaded);
$line3 = 'reload='.json_encode($loaded);

echo $line1, "\n", $line2, "\n", $line3, "\n";
echo 'handler=', ini_get('session.serialize_handler'), "\n";
