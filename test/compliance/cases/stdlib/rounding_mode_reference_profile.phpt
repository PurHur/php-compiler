--TEST--
stdlib RoundingMode enum — not registered on PHP 8.2 reference profile (#14846)
--FILE--
<?php
echo enum_exists('RoundingMode', false) ? "fail\n" : "ok\n";
--EXPECT--
ok
