<?php

declare(strict_types=1);

// Zend 8.2 reference profile: str_increment/str_decrement undefined (#14709).
$si = function_exists('str_increment');
$sd = function_exists('str_decrement');
echo ($si ? 'si_fail' : 'si_ok')."\n";
echo ($sd ? 'sd_fail' : 'sd_ok')."\n";
