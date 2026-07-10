--TEST--
Stdlib: ini_get() unknown key false in inline expression (#17757, ext/standard/ini.c)
--FILE--
<?php
declare(strict_types=1);

$assigned = ini_get('bogus_xyz');
echo 'assign=', var_export($assigned, true), "\n";
echo 'inline=', var_export(false !== ini_get('bogus_xyz'), true), "\n";
--EXPECT--
assign=false
inline=false
