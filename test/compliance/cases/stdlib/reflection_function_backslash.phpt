--TEST--
Stdlib: ReflectionFunction accepts leading backslash global names (#12191, php_reflection.c)
--FILE--
<?php
function gap_reflect_fn(): void {}
$rf = new ReflectionFunction('\\gap_reflect_fn');
echo $rf->getName(), "\n";
echo $rf->isUserDefined() ? "user-ok\n" : "user-bad\n";
--EXPECT--
gap_reflect_fn
user-ok
