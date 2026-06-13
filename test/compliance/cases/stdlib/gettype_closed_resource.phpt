--TEST--
stdlib gettype()/get_debug_type() on closed stream — resource (closed) (#5147, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);

$h = fopen('php://memory', 'r+');
fclose($h);
echo gettype($h), "\n";
echo get_debug_type($h), "\n";
--EXPECT--
resource (closed)
resource (closed)
