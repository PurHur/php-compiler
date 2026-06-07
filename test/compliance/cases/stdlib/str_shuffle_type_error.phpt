--TEST--
stdlib str_shuffle() — TypeError for non-string operand (#4551, ext/standard/string.c)
--FILE--
<?php
try {
    $unused = str_shuffle([]);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    $unused = str_shuffle(new stdClass());
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
echo strlen(str_shuffle('abc')), "\n";
--EXPECT--
TypeError: str_shuffle(): Argument #1 ($string) must be of type string, array given
TypeError: str_shuffle(): Argument #1 ($string) must be of type string, stdClass given
3
