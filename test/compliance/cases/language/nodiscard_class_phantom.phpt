--TEST--
Language: builtin NoDiscard attribute class — not advertised on PHP 8.2 reference profile (#13706)
--FILE--
<?php
echo class_exists('NoDiscard', false) ? "fail\n" : "ok\n";
--EXPECT--
ok
