--TEST--
Language: user implements InternalIterator — compile-time fatal (#13327)
--FILE--
<?php
class UserInternalIterator implements InternalIterator {}
echo "reach\n";
--EXPECT_EXIT--
255
