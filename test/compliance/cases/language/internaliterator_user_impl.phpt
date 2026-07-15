--TEST--
Language: user implements InternalIterator — runtime fatal (#18781, Zend/zend_inheritance.c)
--FILE--
<?php
echo "before\n";
class UserInternalIterator implements InternalIterator {}
echo "reach\n";
--EXPECTF--
before

Fatal error: UserInternalIterator cannot implement InternalIterator - it is not an interface in %s on line %d
--EXPECT_EXIT--
255
