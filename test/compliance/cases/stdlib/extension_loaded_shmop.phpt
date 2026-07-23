--TEST--
stdlib extension_loaded('shmop') dual-advertises with sysvshm (#22426, ext/shmop/shmop.c)
--SKIPIF--
<?php if (!function_exists('shmop_open')) { print 'skip shmop unavailable'; } ?>
--FILE--
<?php
declare(strict_types=1);

echo 'shmop_loaded=', (int) extension_loaded('shmop'), "\n";
echo 'sysvshm_loaded=', (int) extension_loaded('sysvshm'), "\n";
echo 'shmop_fn=', (int) function_exists('shmop_open'), "\n";
echo 'shmop_in_list=', (int) in_array('shmop', get_loaded_extensions(), true), "\n";
echo 'sysvshm_in_list=', (int) in_array('sysvshm', get_loaded_extensions(), true), "\n";
$shmopFuncs = get_extension_funcs('shmop');
echo 'shmop_funcs=', (int) (is_array($shmopFuncs) && in_array('shmop_open', $shmopFuncs, true)), "\n";
$sysvFuncs = get_extension_funcs('sysvshm');
echo 'sysv_no_shmop=', (int) (is_array($sysvFuncs) && !in_array('shmop_open', $sysvFuncs, true)), "\n";
--EXPECT--
shmop_loaded=1
sysvshm_loaded=1
shmop_fn=1
shmop_in_list=1
sysvshm_in_list=1
shmop_funcs=1
sysv_no_shmop=1
