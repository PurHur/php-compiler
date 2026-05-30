--TEST--
Language: abstract private method compile-time fatal (#3548)
--FILE--
<?php
abstract class A {
    abstract private function f(): void;
}
echo "compiled\n";
--EXPECT_EXIT--
255
