--TEST--
Language: interface constant visibility — non-public must compile-error (#6868)
--FILE--
<?php
interface I {
    private const X = 1;
}
echo "unreachable\n";
--EXPECT_EXIT--
255
