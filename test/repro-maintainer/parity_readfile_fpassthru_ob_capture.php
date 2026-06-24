<?php

declare(strict_types=1);

$path = sys_get_temp_dir() . '/phpc_readfile_fpassthru_ob_' . getmypid() . '.txt';
file_put_contents($path, 'payload');

ob_start();
$n = readfile($path);
$buf = ob_get_clean();
if ('payload' !== $buf || 7 !== $n) {
    echo 'readfile_fail buf=', var_export($buf, true), ' n=', $n, "\n";
    exit(1);
}
echo "readfile_ok\n";

$fp = fopen($path, 'r');
ob_start();
$n2 = fpassthru($fp);
$buf2 = ob_get_clean();
fclose($fp);
if ('payload' !== $buf2 || 7 !== $n2) {
    echo 'fpassthru_fail buf=', var_export($buf2, true), ' n=', $n2, "\n";
    exit(1);
}
echo "fpassthru_ok\n";

unlink($path);
