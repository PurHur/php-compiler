<?php
// #27546 — AOT array_merge must match Zend (not segfault after c:main_before_php).
echo implode(',', array_merge([1, 2], [3])), "\n";
echo json_encode(array_merge([1, 2], [3])), "\n";
echo json_encode(array_merge(['a' => 1], ['a' => 2, 'b' => 3])), "\n";
