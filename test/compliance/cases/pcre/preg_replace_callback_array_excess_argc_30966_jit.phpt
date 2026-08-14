--TEST--
pcre preg_replace_callback_array() excess argc ArgumentCountError JIT (#30966, php_pcre.c)
--FILE--
<?php
try {
    preg_replace_callback_array(['/a/' => fn($m) => 'b'], 'a', -1, $c, 0, 1);
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: preg_replace_callback_array() expects at most 5 arguments, 6 given
