--TEST--
JIT: in_array() — TypeError for non-array haystack (#4135)
--FILE--
<?php
class O
{
}
try {
    in_array('x', new O());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: in_array(): Argument #2 ($haystack) must be of type array, O given
