--TEST--
stdlib iterator_count() enum case operand TypeError names enum class (#6232, php-src-strict)
--FILE--
<?php
enum E: string
{
    case A = 'x';
}
try {
    iterator_count(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
iterator_count(): Argument #1 ($iterator) must be of type Traversable|array, E given
