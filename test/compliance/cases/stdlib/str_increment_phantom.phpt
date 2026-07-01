--TEST--
stdlib str_increment()/str_decrement() — not advertised on PHP 8.2 reference profile (#14709)
--FILE--
<?php
echo function_exists('str_increment') ? "si_fail\n" : "si_ok\n";
echo function_exists('str_decrement') ? "sd_fail\n" : "sd_ok\n";
--EXPECT--
si_ok
sd_ok
