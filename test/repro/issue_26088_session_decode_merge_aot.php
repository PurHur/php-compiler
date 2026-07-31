<?php

/**
 * #26088 AOT — session_decode merge + start clear-then-load.
 */
ini_set('session.use_cookies', '0');
$dir = sys_get_temp_dir().'/phpc_26088aot_'.getmypid();
@mkdir($dir);
ini_set('session.save_path', $dir);
session_id('issue26088aotmergeid012345');
session_start();
$_SESSION = ['a' => 1, 'keep' => 'yes'];
session_decode('b|i:2;a|i:99;');
$merged = $_SESSION;
ksort($merged);
$line1 = json_encode($merged);
session_write_close();
$_SESSION = ['stale' => 9];
session_id('issue26088aotmergeid012345');
session_start();
$loaded = $_SESSION;
ksort($loaded);
echo $line1, "\n", json_encode($loaded), "\n";
