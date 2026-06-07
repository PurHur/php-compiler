--TEST--
Language: abstract trait constants rejected at parse time (#7043, Zend/php-parser parity)
--FILE--
<?php
trait U {
    abstract const string NAME;
}
class D {
    use U;
    public const string NAME = 'd';
}
echo D::NAME, "\n";
--EXPECT_EXIT--
255
