<?php

declare(strict_types=1);

$path = sys_get_temp_dir() . '/phpc_aot_readfile_ob_' . getmypid() . '.txt';
file_put_contents($path, 'payload');
ob_start();
$n = readfile($path);
$buf = ob_get_clean();
echo ($buf === 'payload' && $n === 7) ? 'readfile_ok' : 'readfile_bad', "\n";
unlink($path);
