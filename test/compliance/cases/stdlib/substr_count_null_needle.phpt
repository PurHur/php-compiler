--TEST--
stdlib substr_count() null needle — TypeError not empty ValueError (#18312, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

try {
    substr_count('haystack', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    substr_count('haystack', '');
    echo "empty_uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
substr_count(): Argument #2 ($needle) must be of type string, null given
substr_count(): Argument #2 ($needle) must not be empty
