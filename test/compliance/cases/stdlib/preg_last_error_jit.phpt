--TEST--
stdlib preg_last_error() JIT after bad pattern
--FILE--
<?php
preg_match('[', 'x');
echo preg_last_error() === 1 ? '1' : '0', "\n";
--EXPECT--
1
