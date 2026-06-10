--TEST--
stdlib strspn()/strcspn() JIT — TypeError for non-string operands
--FILE--
<?php
try {
    $unused = strspn([], 'a');
    echo "uncaught strspn haystack\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $unused = strspn('a', []);
    echo "uncaught strspn mask\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $unused = strcspn([], 'a');
    echo "uncaught strcspn haystack\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $unused = strcspn('a', new stdClass());
    echo "uncaught strcspn mask\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo strspn('abc', 'a'), "\n";
--EXPECT--
TypeError: strspn(): Argument #1 ($string) must be of type string, array given
TypeError: strspn(): Argument #2 ($characters) must be of type string, array given
TypeError: strcspn(): Argument #1 ($string) must be of type string, array given
TypeError: strcspn(): Argument #2 ($characters) must be of type string, stdClass given
1
