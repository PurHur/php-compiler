--TEST--
AOT: count() TypeError on enum case names enum class (#5916)
--FILE--
<?php
enum E
{
    case A;
}
try {
    count(E::A);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
count(): Argument #1 ($value) must be of type Countable|array, E given
