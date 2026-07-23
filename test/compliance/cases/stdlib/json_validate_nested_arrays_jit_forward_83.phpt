--TEST--
stdlib json_validate() JIT — nested arrays and objects in arrays (issue #7459, #22544)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
echo json_validate('[[]]') ? '1' : '0';
echo "\n";
echo json_validate('[[1]]') ? '1' : '0';
echo "\n";
echo json_validate('[{}]') ? '1' : '0';
echo "\n";
--EXPECT--
1
1
1
