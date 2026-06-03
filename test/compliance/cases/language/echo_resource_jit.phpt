--TEST--
language: JIT echo resource handle (issue #4740, Zend resource to string)
--FILE--
<?php
class C {
    public function __toString(): string { return 'obj'; }
}
echo new C();
echo "\n";
$f = fopen('php://memory', 'r');
echo $f;
echo "\n";
--EXPECT--
obj
Resource id #1
