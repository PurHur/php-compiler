--TEST--
Language: typed class constants — array and string (issue #3592, Zend zend_constants.c)
--FILE--
<?php
class C {
    public const array X = [1, 2];
    public const string S = 'a';
}
echo C::X[0], C::S, "\n";
--EXPECT--
1a
