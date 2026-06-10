--TEST--
stdlib serialize() on closed Resource — i:0; wire format (#5326, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

$f = fopen('php://memory', 'r+');
fclose($f);
echo serialize($f), "\n";

$g = fopen('php://memory', 'r+');
echo serialize($g), "\n";
fclose($g);
echo serialize($g), "\n";
--EXPECT--
i:0;
i:0;
i:0;
