--TEST--
stdlib pcntl_getpriority/setpriority current-process round-trip (#20046, ext/pcntl/pcntl.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['pcntl_getpriority', 'pcntl_setpriority'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}

echo 'PRIO_PROCESS=', defined('PRIO_PROCESS') ? (string) PRIO_PROCESS : 'missing', "\n";
echo 'PRIO_PGRP=', defined('PRIO_PGRP') ? (string) PRIO_PGRP : 'missing', "\n";
echo 'PRIO_USER=', defined('PRIO_USER') ? (string) PRIO_USER : 'missing', "\n";

if (!function_exists('pcntl_getpriority') || !function_exists('pcntl_setpriority')) {
    echo "skip\n";
    exit(0);
}

$p = pcntl_getpriority();
echo 'prio_int=', is_int($p) ? 'Y' : 'N', "\n";
$ok = pcntl_setpriority($p);
echo 'set_ok=', ($ok === true) ? 'Y' : 'N', "\n";
$again = pcntl_getpriority(null, PRIO_PROCESS);
echo 'round_trip=', ($again === $p) ? 'Y' : 'N', "\n";
--EXPECT--
pcntl_getpriority=Y
pcntl_setpriority=Y
PRIO_PROCESS=0
PRIO_PGRP=1
PRIO_USER=2
prio_int=Y
set_ok=Y
round_trip=Y
