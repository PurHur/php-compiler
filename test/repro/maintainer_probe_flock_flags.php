<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/flock_test_' . getmypid() . '.txt';
file_put_contents($tmp, 'data');

$fp = fopen($tmp, 'r+');
var_export(flock($fp, LOCK_EX | LOCK_NB));
echo "\n";
flock($fp, LOCK_UN);
fclose($fp);

var_export(flock(fopen($tmp, 'r+'), LOCK_EX));
echo "\n";

unlink($tmp);
echo "ok\n";
