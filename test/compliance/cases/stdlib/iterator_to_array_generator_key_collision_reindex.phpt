--TEST--
iterator_to_array() preserve_keys=false reindexes generator (issue #8817)
--FILE--
<?php
$gen = (function () {
    yield 1;
    yield from (function () {
        yield 2;
    })();
})();
var_export(iterator_to_array($gen, false));
--EXPECT--
array (
  0 => 1,
  1 => 2,
)
