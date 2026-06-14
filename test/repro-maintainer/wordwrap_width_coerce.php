<?php

echo wordwrap('hello', 1.9), "\n";
echo wordwrap('hello world', 3.7), "\n";
echo wordwrap('hello world', '3'), "\n";

try {
    wordwrap('hello', 'abc');
    echo "invalid string uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    wordwrap('hello', []);
    echo "array uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
