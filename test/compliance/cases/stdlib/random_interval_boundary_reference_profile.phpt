--TEST--
stdlib Random\IntervalBoundary enum — not registered on PHP 8.2 reference profile (#14847)
--FILE--
<?php
echo enum_exists('Random\IntervalBoundary', false) ? "fail\n" : "ok\n";
--EXPECT--
ok
