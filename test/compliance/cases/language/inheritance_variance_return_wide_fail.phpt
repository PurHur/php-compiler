--TEST--
inheritance variance: widened return type rejected at compile time (issue #3323)
--FILE--
<?php
class Child {}
class Base { public function create(): Child { return new Child(); } }
class Sub extends Base { public function create(): Base { return new Child(); } }
echo "ok\n";
--EXPECT_EXIT--
255
