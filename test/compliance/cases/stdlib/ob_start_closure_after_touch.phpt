--TEST--
stdlib ob_start() inline Closure after touch() — stmt-level side effect must not break callback slot (#17846)
--FILE--
<?php
$p = sys_get_temp_dir() . '/phpc_ob_touch_' . getmypid() . '.tmp';
touch($p, 1);
ob_start(fn($b) => strtoupper($b));
echo 'hi';
ob_end_flush();
@unlink($p);
--EXPECT--
HI
