--TEST--
stdlib builtin stub enums — not registered on PHP 8.2 reference profile (#13630)
--FILE--
<?php
echo enum_exists('PadType', false) ? "fail\n" : "ok\n";
echo enum_exists('StringTrimMode', false) ? "fail\n" : "ok\n";
echo enum_exists('MemoryUsage', false) ? "fail\n" : "ok\n";
echo enum_exists('ExitStatus', false) ? "fail\n" : "ok\n";
echo enum_exists('SocketType', false) ? "fail\n" : "ok\n";
echo enum_exists('PhpInputFilter', false) ? "fail\n" : "ok\n";
--EXPECT--
ok
ok
ok
ok
ok
ok
