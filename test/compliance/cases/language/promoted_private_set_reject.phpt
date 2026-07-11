--TEST--
Language: promoted constructor public (private(set)) — parses and reads on 8.4 profile (#16495, Zend/zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public (private(set)) int $x = 1) {}
}
echo (new D())->x, "\n";
--EXPECT--
1
