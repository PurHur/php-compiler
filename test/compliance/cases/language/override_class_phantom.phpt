--TEST--
Language: builtin Override attribute class — not advertised on PHP 8.2 reference profile (#12387)
--FILE--
<?php
echo class_exists('Override', false) ? "fail\n" : "ok\n";
--EXPECT--
ok
