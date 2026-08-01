--TEST--
Language: eval('class …') must keep the literal body (no MCJIT pad rewrite) (#26424)
--FILE--
<?php
eval('class PadProbeSq26424 {}');
echo class_exists('PadProbeSq26424') ? "ok\n" : "no\n";
--EXPECT--
ok
