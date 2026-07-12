--TEST--
Language: ReflectionParameter internal function strlen (#18337)
--FILE--
<?php
declare(strict_types=1);

echo (new ReflectionParameter('strlen', 0))->getName(), "\n";
echo (new ReflectionParameter('strlen', 0))->getType()->getName(), "\n";
echo (new ReflectionParameter('array_map', 0))->getName(), "\n";
?>
--EXPECT--
string
string
callback
