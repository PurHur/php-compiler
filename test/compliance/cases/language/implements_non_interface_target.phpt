--TEST--
Language: class implements class — compile-time fatal (#12971)
--FILE--
<?php
class A {}
class B implements A {}
echo "reach\n";
--EXPECT_EXIT--
255
