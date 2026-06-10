--TEST--
stdlib similar_text() — TypeError for array/object operands (#4543, ext/standard/string.c)
--FILE--
<?php
try {
    similar_text([], 'x');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    similar_text(new stdClass(), 'x');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: similar_text(): Argument #1 ($string1) must be of type string, array given
TypeError: similar_text(): Argument #1 ($string1) must be of type string, stdClass given
