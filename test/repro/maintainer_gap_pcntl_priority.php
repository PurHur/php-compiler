<?php
echo "get_exists=", function_exists("pcntl_getpriority") ? 1 : 0, "\n";
echo "set_exists=", function_exists("pcntl_setpriority") ? 1 : 0, "\n";
if (!function_exists("pcntl_getpriority")) { echo "MISSING\n"; exit(0); }
$p = pcntl_getpriority();
echo "prio=", var_export($p, true), "\n";
$ok = pcntl_setpriority($p);
echo "set_ok=", ($ok === true || $ok === 0) ? 1 : 0, "\n";
