--TEST--
AOT: base_convert(null) soft-null coerce to '0' on 8.4 (#29320; DEP text guarded on VM/JIT)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// AOT set_error_handler for DEP text is a separate gap; coerce result must match Zend.
echo base_convert(null, 10, 16), "\n";
--EXPECT--
0
