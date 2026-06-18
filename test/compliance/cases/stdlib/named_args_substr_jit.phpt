--TEST--
substr() named offset/length arguments (JIT, issue #9106)
--FILE--
<?php
echo substr('hello', offset: 1, length: 3), "\n";
--EXPECT--
ell
