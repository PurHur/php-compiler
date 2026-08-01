--TEST--
Language: ambiguous interface constants — file scope (#24699 / #26672)
--FILE--
<?php
interface I { const C = 1; }
interface J { const C = 2; }
class X implements I, J {}
echo "ok\n";
--EXPECTF--
Fatal error: Class X inherits both I::C and J::C, which is ambiguous in %s on line %d
--EXPECT_EXIT--
255
