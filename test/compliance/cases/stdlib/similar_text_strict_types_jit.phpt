--TEST--
stdlib similar_text() JIT — strict call-site TypeError on int operands (#9519)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    similar_text(123, '123');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: similar_text(): Argument #1 ($string1) must be of type string, int given
