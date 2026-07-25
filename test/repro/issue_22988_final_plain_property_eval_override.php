<?php
// Issue #22988 — separate eval must not override final plain property (Zend/zend_inheritance.c).
class A {
    final public string $x = 'a';
}
eval('class B extends A { public string $x = "b"; }');
echo "EVAL_OK\n";
echo (new B)->x, "\n";
