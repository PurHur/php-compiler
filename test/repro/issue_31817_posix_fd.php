<?php

// #31817 — user-script file I/O / echo after LibcExtern open/close/read/write drop
$path = sys_get_temp_dir() . '/phpc_31817_' . getmypid() . '.txt';
file_put_contents($path, 'ab');
echo file_get_contents($path), "\n";
unlink($path);
echo "ok\n";
