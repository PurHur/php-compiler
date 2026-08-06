<?php
// repro #27130 — thin AOT array_reverse must match Zend/VM/JIT
echo json_encode(array_reverse([1, 2, 3])), "\n";
