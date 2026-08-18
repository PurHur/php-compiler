--TEST--
AOT: integer ** operator must verify and match Zend (#31966)
--FILE--
<?php
var_dump(2 ** 10);
--EXPECT--
int(1024)
--EXPECT_EXIT--
0
