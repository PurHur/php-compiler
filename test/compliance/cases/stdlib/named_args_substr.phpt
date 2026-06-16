--TEST--
substr() named offset/length arguments (VM, issue #9106)
--FILE--
<?php
echo substr('hello', offset: 1, length: 3), "\n";
echo substr('hello', length: 2, offset: 1), "\n";
--EXPECT--
ell
el
