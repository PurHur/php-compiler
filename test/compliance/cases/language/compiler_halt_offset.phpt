--TEST--
Language: __COMPILER_HALT_OFFSET__ matches Zend byte offset (#5455)
--FILE--
<?php
echo __COMPILER_HALT_OFFSET__, "\n";
__halt_compiler();
TRAILING
--EXPECT--
61
