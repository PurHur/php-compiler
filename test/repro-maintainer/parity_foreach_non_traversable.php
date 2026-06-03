<?php

/** Issue #4879 — foreach on scalar warns (E_WARNING), exits 0 (Zend/zend_vm_def.h). */

foreach (true as $v) {
    echo "body\n";
}
echo "done\n";
