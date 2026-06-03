--TEST--
language: JIT echo object/array with __toString (issue #4740, Zend zend_operators.c)
--FILE--
<?php
class C {
    public function __toString(): string { return 'obj'; }
}
echo new C();
echo "\n";
$a = ['k' => 1];
echo $a;
echo "\n";
--EXPECT--
obj
Array
