<?php
declare(strict_types=1);
// Zend coerces scalars to string
echo similar_text(123, '123'), "\n";

try {
    similar_text([], 'x');
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

$p = 0.0;
similar_text('hello', 'hello world', $p);
var_export($p > 99.0);
echo "\n";
