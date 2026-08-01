<?php
// Repro #26537 — class extends trait must CompileError (Zend/zend_inheritance.c).
trait T {}
class C extends T {}
echo "ok\n";
