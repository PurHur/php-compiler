<?php
/**
 * Repro #26383 — get_headers(file://) must Warning + false (php-src head.c).
 */
error_reporting(E_ALL);
$saw = false;
set_error_handler(function ($no, $msg) use (&$saw) {
    if ($no === E_WARNING) {
        $saw = true;
        echo "WARN:$msg\n";
        return true;
    }
    return false;
});
file_put_contents('/tmp/t_gh_26383.txt', 'x');
$h = get_headers('file:///tmp/t_gh_26383.txt');
echo 'saw=', $saw ? '1' : '0', ' result=', var_export($h, true), "\n";
