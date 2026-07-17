--TEST--
spl iterator_apply() JIT — Traversable only TypeError (#19839, php-src-strict)
--FILE--
<?php
try {
    iterator_apply(null, fn () => true);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    iterator_apply([1, 2], fn () => true);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
iterator_apply(): Argument #1 ($iterator) must be of type Traversable, null given
iterator_apply(): Argument #1 ($iterator) must be of type Traversable, array given
