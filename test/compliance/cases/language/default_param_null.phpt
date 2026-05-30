--TEST--
Default parameter: nullable null
--FILE--
<?php
function f(?int $a = null): int { return $a ?? 0; }
echo f(), "\n";
--EXPECT--
0
