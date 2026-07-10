<?php

declare(strict_types=1);

// php-compiler-language-profile=8.4
// Maintainer gap: bare `throw;` catch rethrow (#17691, Zend/zend_compile.c).

try {
    try {
        throw new Exception('inner');
    } catch (Exception $e) {
        throw;
    }
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
