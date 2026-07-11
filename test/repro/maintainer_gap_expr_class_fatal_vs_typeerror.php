<?php

declare(strict_types=1);

// Compile-time fatal — must not be catchable (Zend/zend_compile.c; #17949).
try {
    echo (1 + 2)::class;
} catch (Error $e) {
    echo "caught\n";
}
