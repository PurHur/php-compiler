--TEST--
Language: class constant `new` expression under JIT (#10198, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public const X = new stdClass();
}
var_export(C::X);
echo C::X === C::X ? "\n1\n" : "\n0\n";
--EXPECT--
(object) array (
)
1
