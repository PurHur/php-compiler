<?php
// Repro #26382 — unit enum case must not have a value (Zend/zend_compile.c)
enum E {
    case A = 1;
}
echo "should not run\n";
