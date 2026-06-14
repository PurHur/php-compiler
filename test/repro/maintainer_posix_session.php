<?php
foreach (['posix_getsid', 'posix_getpgid', 'posix_setpgid'] as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'no', "\n";
}
$pid = posix_getpid();
$pgid = posix_getpgid($pid);
$sid = posix_getsid($pid);
echo ($pgid !== false && $pgid > 0 ? 'pgid-ok' : 'pgid-bad'), "\n";
echo ($sid !== false && $sid > 0 ? 'sid-ok' : 'sid-bad'), "\n";
