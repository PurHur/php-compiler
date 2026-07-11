--TEST--
stdlib var_export() NAN/INF and (string) NAN (#15328, ext/standard/var.c)
--FILE--
<?php
echo var_export(NAN, true), "\n";
echo var_export(INF, true), "\n";
echo var_export((string) NAN, true), "\n";
--EXPECT--
NAN
INF
'NAN'
