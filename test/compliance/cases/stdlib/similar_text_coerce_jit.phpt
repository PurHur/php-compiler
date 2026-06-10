--TEST--
stdlib similar_text() JIT — scalar-to-string coercion and &$percent (#4543)
--JIT--
--FILE--
<?php
echo similar_text(123, '123'), "\n";
echo similar_text(12.5, '12.5'), "\n";

$p = 0.0;
similar_text('hello', 'hello world', $p);
echo $p, "\n";

try {
    similar_text([], 'x');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo 'similar_text array: ', get_class($e), "\n";
}
--EXPECT--
3
4
62.5
similar_text array: TypeError
