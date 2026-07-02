--TEST--
Language: promoted constructor public private(set) — parses and reads publicly (#14946, Zend/zend_compile.c)
--FILE--
<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
}
$d = new D();
echo $d->x, "\n";
--EXPECT--
1
