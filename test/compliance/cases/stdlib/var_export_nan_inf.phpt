--TEST--
stdlib var_export() NAN/INF float tokens (#4633, ext/standard/var.c)
--FILE--
<?php
echo var_export(fdiv(0.0, 0.0), true), "\n";
echo var_export(fdiv(1.0, 0.0), true), "\n";
echo var_export(-NAN, true), "\n";
--EXPECT--
NAN
INF
NAN
