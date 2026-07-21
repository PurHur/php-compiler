--TEST--
Language: class extends Attribute compile-time fatal (#21669)
--FILE--
<?php
class Bad extends Attribute {}
echo "ok\n";
--EXPECT_EXIT--
255
