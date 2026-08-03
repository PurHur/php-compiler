<?php
// #27181 — AOT preg_filter must match Zend for array + string subjects (no abort).
echo json_encode(preg_filter('/a/', 'b', ['a1', 'x', 'aa'])) . "\n";
echo json_encode(preg_filter('/a/', 'b', 'a1')) . "\n";
