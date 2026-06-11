--TEST--
stdlib array_search() — TypeError for non-array haystack (#4135, ext/standard/array.c)
--FILE--
<?php
class O
{
    public int $x = 1;
}
$o = new O();
try {
    array_search('x', $o);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_search(0, 'not-array');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_search(): Argument #2 ($haystack) must be of type array, O given
TypeError: array_search(): Argument #2 ($haystack) must be of type array, string given
