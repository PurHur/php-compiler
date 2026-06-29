<?php

$fail = 0;

$path = sys_get_temp_dir() . '/phpc_fseek_float_' . getmypid() . '.txt';
file_put_contents($path, '0123456789');
$fh = fopen($path, 'r');
if (false === $fh) {
    echo "FAIL fopen\n";
    exit(1);
}

fseek($fh, 2.7);
$pos = ftell($fh);
fclose($fh);
@unlink($path);

if (2 !== $pos) {
    echo "FAIL fseek float offset: got ";
    var_export($pos);
    echo " expected 2\n";
    exit(1);
}

exit(0);
