--TEST--
stdlib ob_start() typed Closure callback — ob_get_clean raw buffer, ob_end_flush applies handler (#17861)
--FILE--
<?php
ob_start(static fn (string $buffer, int $phase): string => strtoupper($buffer));
echo 'hi';
echo ob_get_clean();
--EXPECT--
hi
