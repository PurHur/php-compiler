<?php
$path = sys_get_temp_dir() . '/phpc_34423_' . getmypid() . '.txt';
file_put_contents($path, "hi\n");
$got = file_get_contents($path);
@unlink($path);
echo 'putget:', var_export($got, true), "\n";
echo 'url:', parse_url('https://example.com/x?y=1', PHP_URL_HOST), "\n";
echo 'DONE', "\n";
