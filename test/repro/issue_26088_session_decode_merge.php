<?php

/**
 * #26088 — session_decode() merges into existing $_SESSION (php-src mod_php.c).
 *
 * Avoid printing before the second session_start (headers_sent).
 */
ini_set('session.use_cookies', '0');
$dir = sys_get_temp_dir().'/phpc_26088_'.getmypid();
@mkdir($dir);
ini_set('session.save_path', $dir);

session_id('issue26088mergeid012345678');
session_start();
$_SESSION = ['a' => 1, 'keep' => 'yes'];
session_decode('b|i:2;a|i:99;');
$merged = $_SESSION;
ksort($merged);
$line1 = json_encode($merged);

session_write_close();
$_SESSION = ['stale' => 9, 'disk' => 2];
session_id('issue26088mergeid012345678');
session_start();
$loaded = $_SESSION;
ksort($loaded);
$line2 = json_encode($loaded);

echo $line1, "\n", $line2, "\n";
