--TEST--
Language: typed class constant type mismatch — compile-time TypeError (#3592)
--FILE--
<?php
class C {
    public const string S = 1;
}
--EXPECT_EXIT--
255
