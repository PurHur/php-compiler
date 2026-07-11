--TEST--
stdlib ob_start() Closure callback not applied on ob_get_clean() (#17861, ext/standard/output.c)
--FILE--
<?php

ob_start(static fn (string $b, int $p): string => strtoupper($b));
echo 'hi';
echo ob_get_clean();
--EXPECT--
hi
