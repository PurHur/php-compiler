<?php
declare(strict_types=1);
// Zend TypeErrors on int operands when strict_types=1 is in effect at the call site
try {
    similar_text(123, '123');
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

$p = 0.0;
similar_text('hello', 'hello world', $p);
var_export($p > 99.0);
echo "\n";
