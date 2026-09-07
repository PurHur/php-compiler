<?php
$n = (int) ($argv[1] ?? 1000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $k = "k".$i;
    $a = [$k => $i];
    unset($a);
}
echo 'named_cv delta=', (memory_get_usage(false) - $u0), "\n";
