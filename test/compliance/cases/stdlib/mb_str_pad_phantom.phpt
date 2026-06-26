--TEST--
stdlib mb_str_pad() — not advertised on PHP 8.2 reference profile (#11964)
--FILE--
<?php
echo function_exists('mb_str_pad') ? "fail\n" : "ok\n";
--EXPECT--
ok
