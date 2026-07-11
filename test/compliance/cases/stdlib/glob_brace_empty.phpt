--TEST--
stdlib glob() GLOB_BRACE — no matches returns empty array (#12626, ext/standard/dir.c)
--FILE--
<?php
$result = glob('{a,b}.txt', GLOB_BRACE);
var_export($result === []);
echo "\n";
--EXPECT--
true
