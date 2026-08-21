<?php
// #33433 — AOT fopen PHP modes c+/x+ (libc fopen rejects; need open+fdopen).
$base = sys_get_temp_dir().'/phpc_fopen_33433_'.getmypid();
@unlink($base.'_c');
@unlink($base.'_x');
@unlink($base.'_xexist');
file_put_contents($base.'_xexist', 'pre');

$fc = fopen($base.'_c', 'c+');
echo $fc ? "c_ok\n" : "c_fail\n";
if ($fc) {
    fwrite($fc, 'hello');
    ftruncate($fc, 2);
    fclose($fc);
    echo 'c_body=', file_get_contents($base.'_c'), "\n";
}

$fx = fopen($base.'_x', 'x+');
echo $fx ? "x_ok\n" : "x_fail\n";
if ($fx) {
    fwrite($fx, 'xy');
    fclose($fx);
    echo 'x_body=', file_get_contents($base.'_x'), "\n";
}

$fxe = @fopen($base.'_xexist', 'x+');
echo $fxe ? "xexist_ok\n" : "xexist_fail\n";

@unlink($base.'_c');
@unlink($base.'_x');
@unlink($base.'_xexist');
