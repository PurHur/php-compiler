--TEST--
AOT: glob()/fnmatch() pattern null soft-null coerce on 8.4 (#21366, #29659, #29660; DEP text guarded on VM/JIT)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// AOT set_error_handler for DEP text is a separate gap; coerce result must match Zend.
echo count(glob(null)), "\n";
echo fnmatch(null, 'a') ? "1\n" : "0\n";
--EXPECT--
0
0
