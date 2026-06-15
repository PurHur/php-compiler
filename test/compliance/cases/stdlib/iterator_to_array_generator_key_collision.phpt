--TEST--
iterator_to_array() generator key collision overwrites (issue #8817, ext/spl/iterator.c)
--FILE--
<?php
$gen = (function () {
    yield 1;
    yield from (function () {
        yield 2;
    })();
})();
var_export(iterator_to_array($gen));
--EXPECT--
array (
  0 => 2,
)
