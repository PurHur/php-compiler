<?php

// Repro for #26977 — AOT array_replace_recursive NestedJIT (Done-when: json_encode vs Zend)
echo json_encode(array_replace_recursive(['a' => ['b' => 1]], ['a' => ['c' => 2]])), PHP_EOL;
echo json_encode(array_replace_recursive(['a' => ['b' => 1]], ['a' => ['b' => 2, 'c' => 3]])), PHP_EOL;
echo json_encode(array_replace_recursive([1, 2, 3], [0 => 10, 2 => ['z' => 9]])), PHP_EOL;
