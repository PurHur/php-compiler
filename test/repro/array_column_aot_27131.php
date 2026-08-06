<?php
// repro #27131 — thin AOT json_encode(array_column) must match Zend/VM/JIT
echo json_encode(array_column([['id' => 1], ['id' => 2]], 'id')), "\n";
