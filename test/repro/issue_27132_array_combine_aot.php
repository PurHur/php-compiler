<?php

declare(strict_types=1);

/**
 * Thin AOT execute guard for array_combine() (#27132).
 * php-src: ext/standard/array.c PHP_FUNCTION(array_combine)
 */
echo json_encode(array_combine(['a', 'b'], [1, 2])), "\n";
