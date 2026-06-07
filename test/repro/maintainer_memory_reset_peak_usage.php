<?php
$before = memory_get_peak_usage();
$str = str_repeat('x', 1 << 20);
$peak = memory_get_peak_usage();
unset($str);
memory_reset_peak_usage();
$after = memory_get_peak_usage();
echo ($peak > $before ? "peak_grew\n" : "peak_flat\n");
echo ($after < $peak ? "reset_ok\n" : "reset_fail\n");
