--TEST--
Language: exit()/die() — float/bool/null status coercion (#4696, zend_exit)
--FILE--
<?php
exit(1.5);
--EXPECT--
1.5
--EXPECT_EXIT--
0
