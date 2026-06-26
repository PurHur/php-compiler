--TEST--
JIT: feof() true after fread() past EOF on php://memory (#11955, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);

$h = fopen('php://memory', 'r+');
fwrite($h, 'hello');
rewind($h);
fread($h, 9999);
echo feof($h) ? '1' : '0', "\n";
fclose($h);
--EXPECT--
1
--CREDITS--
PurHur/php-compiler issue #11955
