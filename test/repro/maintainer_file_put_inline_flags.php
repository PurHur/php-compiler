<?php

declare(strict_types=1);

$f = sys_get_temp_dir() . '/fpc_inline_flags_' . getmypid() . '.txt';
@unlink($f);
$r = file_put_contents($f, 'a', FILE_APPEND | LOCK_EX);
var_dump($r);
echo file_get_contents($f);
@unlink($f);
