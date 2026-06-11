--TEST--
JIT: array_search() — TypeError for non-array haystack (#4135)
--FILE--
<?php
class O
{
}
try {
    array_search('x', new O());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_search(): Argument #2 ($haystack) must be of type array, O given
