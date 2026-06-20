--TEST--
Language: bare `new` in class constant allowed PHP 8.3+ (#10198, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public const X = new stdClass;
}
echo C::X instanceof stdClass ? "1\n" : "0\n";
--EXPECT--
1
