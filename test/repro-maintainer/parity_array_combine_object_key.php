<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: array_combine() object keys must Error (ext/standard/array.c #4161).
 */

try {
    array_combine([new stdClass()], [1]);
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
