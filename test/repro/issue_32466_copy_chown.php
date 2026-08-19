<?php
// Repro #32466 — leftover Type.php always-on __compiler_copy/chown/chgrp dropped.
// copy()/chown()/chgrp() AOT must still compile (php-src ext/standard/file.c + filestat.c).
$from = sys_get_temp_dir().'/phpc_32466_from_'.getmypid();
$to = sys_get_temp_dir().'/phpc_32466_to_'.getmypid();
file_put_contents($from, 'hi');
@unlink($to);
echo copy($from, $to) ? "copy_ok\n" : "copy_bad\n";
echo file_get_contents($to) === 'hi' ? "payload_ok\n" : "payload_bad\n";
$uid = function_exists('posix_geteuid') ? posix_geteuid() : fileowner($to);
echo chown($to, $uid) ? "chown_ok\n" : "chown_bad\n";
$gid = function_exists('posix_getegid') ? posix_getegid() : filegroup($to);
echo chgrp($to, $gid) ? "chgrp_ok\n" : "chgrp_bad\n";
@unlink($from);
@unlink($to);
