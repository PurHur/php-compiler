--TEST--
Language: iterator_to_array on generator with yield then yield from — key collision keeps last value (#9015, spl_iterators.c)
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
