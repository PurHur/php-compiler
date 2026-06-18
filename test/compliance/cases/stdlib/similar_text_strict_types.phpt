--TEST--
stdlib similar_text() — strict call-site TypeError on int operands (#9519, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
try {
    similar_text(123, '123');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$p = 0.0;
similar_text('hello', 'hello world', $p);
var_export($p > 99.0);
echo "\n";
--EXPECT--
TypeError: similar_text(): Argument #1 ($string1) must be of type string, int given
false
