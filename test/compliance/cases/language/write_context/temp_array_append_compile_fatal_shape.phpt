--TEST--
Language: temporary write context emits Zend-shaped Fatal error not parseAndCompile failure (#29769)
--FILE--
<?php
[1, 2][] = 3;
--EXPECTF--
Fatal error: Cannot use temporary expression in write context in %s on line %d
