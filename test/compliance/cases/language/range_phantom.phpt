--TEST--
language Range class — not registered on PHP 8.2 reference profile (#17427)
--FILE--
<?php
echo class_exists('Range', false) ? "fail\n" : "ok\n";
--EXPECT--
ok
