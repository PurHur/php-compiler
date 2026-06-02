--TEST--
stdlib chunk_split() — TypeError for non-string $string (#4580, ext/standard/string.c)
--FILE--
<?php
try {
    $unused = chunk_split([]);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    $unused = chunk_split(new stdClass());
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
echo chunk_split('1234567890', 3, '-'), "\n";
--EXPECT--
TypeError: chunk_split(): Argument #1 ($string) must be of type string, array given
TypeError: chunk_split(): Argument #1 ($string) must be of type string, stdClass given
123-456-789-0-
