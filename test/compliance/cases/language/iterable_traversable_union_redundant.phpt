--TEST--
Language: iterable|Traversable union type — runtime fatal, Duplicate type Traversable is redundant (#26564/#26591, zend_compile.c)
--FILE--
<?php
function f(iterable|Traversable $x): string {
    return 't';
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Duplicate type Traversable is redundant in %s on line %d
