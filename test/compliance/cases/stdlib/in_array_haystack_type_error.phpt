--TEST--
stdlib in_array() — TypeError for non-array haystack (#4135, ext/standard/array.c)
--FILE--
<?php
class O
{
    public int $x = 1;
}
$o = new O();
try {
    in_array('x', $o);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    in_array(0, 'not-array');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: in_array(): Argument #2 ($haystack) must be of type array, O given
TypeError: in_array(): Argument #2 ($haystack) must be of type array, string given
