--TEST--
stdlib count() TypeError on enum case names enum class (#5916, ext/standard/array.c)
--FILE--
<?php
enum E
{
    case A;
}
try {
    count(E::A);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "done\n";
--EXPECT--
TypeError: count(): Argument #1 ($value) must be of type Countable|array, E given
done
