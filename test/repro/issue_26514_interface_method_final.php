<?php
// Repro #26514: interface method must not be final (Zend/zend_compile.c).
interface I {
    final public function f(): void;
}
echo "ok\n";
