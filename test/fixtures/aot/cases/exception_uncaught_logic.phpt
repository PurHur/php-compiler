--TEST--
AOT: uncaught LogicException prints Zend-shaped fatal (#23641)
--FILE--
<?php
echo "BEFORE\n";
throw new LogicException("boom from user code");
--EXPECT--
BEFORE
--EXPECT_EXIT--
255
