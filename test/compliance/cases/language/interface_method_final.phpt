--TEST--
Language: interface method final — must compile-error (#26514)
--FILE--
<?php
interface I {
    final public function f(): void;
}
echo "unreachable\n";
--EXPECTF--
Fatal error: Interface method I::f() must not be final in %s on line %d
--EXPECT_EXIT--
255
