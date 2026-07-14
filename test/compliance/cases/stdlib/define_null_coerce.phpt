--TEST--
define(null, value) coerces empty constant name (ext/standard/basic_functions.c; #18959)
--FILE--
<?php
$ok = define(null, 42);
echo 'define=', ($ok ? 'true' : 'false'), ' constant=', constant(''), "\n";
?>
--EXPECT--
define=true constant=42
