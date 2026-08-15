--TEST--
stdlib: get_headers() excess argc at-most wording (#31192)
--FILE--
<?php
try {
    get_headers('http://example.com', false, null, 'x');
    echo "excess NO_THROW\n";
} catch (Throwable $e) {
    echo 'excess ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    get_headers();
    echo "missing NO_THROW\n";
} catch (Throwable $e) {
    echo 'missing ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
excess ArgumentCountError: get_headers() expects at most 3 arguments, 4 given
missing ArgumentCountError: get_headers() expects at least 1 argument, 0 given
