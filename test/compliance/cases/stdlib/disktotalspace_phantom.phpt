--TEST--
stdlib disktotalspace() — not advertised on PHP 8.2 reference profile (#18017, ext/standard/filestat.c)
--FILE--
<?php
echo function_exists('disktotalspace') ? "fail\n" : "ok\n";
echo function_exists('diskfreespace') ? "free_alias=1\n" : "free_alias=0\n";
--EXPECT--
ok
free_alias=1
