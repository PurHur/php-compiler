<?php

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/phpc-touch-neg-' . getmypid() . '.txt';
file_put_contents($tmp, 'x');
touch($tmp, -1);
echo 'mtime_neg1=', filemtime($tmp), "\n";
@unlink($tmp);

$tmp2 = sys_get_temp_dir() . '/phpc-touch-zero-' . getmypid() . '.txt';
file_put_contents($tmp2, 'x');
touch($tmp2, 0);
echo 'mtime_zero=', filemtime($tmp2), "\n";
@unlink($tmp2);
