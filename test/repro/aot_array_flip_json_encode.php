<?php

// Repro for #27072 — AOT json_encode(array_flip(lit)) vs Zend
echo json_encode(array_flip(['a', 'b'])), PHP_EOL;
echo json_encode(array_flip(['x' => 10, 'y' => 20])), PHP_EOL;
