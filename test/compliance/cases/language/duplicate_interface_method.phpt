--TEST--
Language: duplicate interface method — compile-time fatal (#5218)
--FILE--
<?php
interface I {
    public function x();
    public function x();
}
echo "run\n";
--EXPECT_EXIT--
255
