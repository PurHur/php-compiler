--TEST--
Language: interface method visibility — non-public must compile-error (#6677)
--FILE--
<?php
interface I {
    protected function f(): void;
}
echo "unreachable\n";
--EXPECT_EXIT--
255
