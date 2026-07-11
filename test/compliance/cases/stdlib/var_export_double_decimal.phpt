--TEST--
stdlib var_export() finite double decimal form (#14707, ext/standard/var.c php_var_export_double)
--FILE--
<?php
echo var_export(150.0, true), "\n";
echo var_export(100.0, true), "\n";
echo var_export(sscanf('1.5e2', '%f')[0], true), "\n";
echo var_export(1.0E-10, true), "\n";
--EXPECT--
150.0
100.0
150.0
1.0E-10
