--TEST--
Language: class extends interface — compile-time fatal (#12971)
--FILE--
<?php
interface I {}
class C extends I {}
echo "reach\n";
--EXPECT_EXIT--
255
