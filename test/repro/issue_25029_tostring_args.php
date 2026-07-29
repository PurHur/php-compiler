<?php
// #25029 — __toString must not take arguments (Zend/zend_compile.c).
class A { public function __toString($x) { return "x"; } }
echo "ok\n";
