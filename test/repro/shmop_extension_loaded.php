<?php
declare(strict_types=1);

// #22426 — Zend advertises extension_loaded('shmop') separately from sysvshm
echo (int) extension_loaded('shmop'), "\n";
echo (int) function_exists('shmop_open'), "\n";
echo (int) extension_loaded('sysvshm'), "\n";
echo (int) in_array('shmop', get_loaded_extensions(), true), "\n";
$funcs = get_extension_funcs('shmop');
echo (int) (is_array($funcs) && in_array('shmop_open', $funcs, true)), "\n";
$sysv = get_extension_funcs('sysvshm');
echo (int) (is_array($sysv) && !in_array('shmop_open', $sysv, true)), "\n";
