<?php
/**
 * AOT: rmdir() must compile and match Zend — StringRmdir insert-block restore (#33403).
 * Peer: StringChmod #19283 / StringMkdir #33402.
 *
 * Argv[1] optional existing empty directory (created by the harness). Without it,
 * rmdir a missing path and expect false — avoids AOT mkdir (#33402) in this probe.
 */
$path = $argv[1] ?? (sys_get_temp_dir().'/phpc-rmdir-missing-'.getmypid());
var_dump(rmdir($path));
if (isset($argv[1])) {
    var_dump(is_dir($path));
}
