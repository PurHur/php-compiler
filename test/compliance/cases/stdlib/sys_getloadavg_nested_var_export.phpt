--TEST--
stdlib sys_getloadavg() nested in var_export() — zero-arg array return wired (#15438, ext/standard/basic_functions.c)
--SKIPIF--
<?php
if (!function_exists('sys_getloadavg') && !is_readable('/proc/loadavg')) {
    die('skip sys_getloadavg unavailable');
}
--FILE--
<?php
declare(strict_types=1);

echo var_export(sys_getloadavg(), true), "\n";
--EXPECT--
array (
  0 => %f,
  1 => %f,
  2 => %f,
)
