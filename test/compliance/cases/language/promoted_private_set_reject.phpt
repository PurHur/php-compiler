--TEST--
Language: promoted constructor public (private(set)) — compile rejected (#16436, Zend/zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public (private(set)) int $x = 1) {}
}
echo "should not run\n";
--EXPECT_EXIT--
255
