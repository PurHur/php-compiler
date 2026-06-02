--TEST--
Language: traits — method collision without insteadof is a fatal compile error
--FILE--
<?php
trait T1 { public function f() { return 't1'; } }
trait T2 { public function f() { return 't2'; } }
class C { use T1, T2; }
echo "unreached\n";
--EXPECT_EXIT--
255
