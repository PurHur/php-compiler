--TEST--
Language: typed trait constants rejected on 8.2 target (#5212, Zend/zend_compile.c)
--FILE--
<?php
trait T {
    public const string X = 'a';
}
class C {
    use T;
}
echo C::X, "\n";
--EXPECT_EXIT--
255
