--TEST--
Language: class constants with object expressions rejected at compile — AOT (#9804)
--FILE--
<?php
class C {
    public const X = new stdClass();
}
echo (C::X instanceof stdClass) ? "1\n" : "0\n";
--EXPECT_EXIT--
255
