<?php
/**
 * #27031 — AOT http_build_query nested array must match Zend (no abort).
 */
echo http_build_query(['a' => 1, 'b' => ['c' => 2]]), PHP_EOL;
echo http_build_query(['a' => 1, 'b' => 2]), PHP_EOL;
echo http_build_query(['x' => 'y z']), PHP_EOL;
