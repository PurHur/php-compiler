<?php
/**
 * Repro #24884 — eval() of a child overriding a final method must E_COMPILE_ERROR like Zend.
 */
class A
{
    final public function f(): void
    {
    }
}
eval('class B extends A { public function f(): void {} }');
echo "EVAL_OK\n";
