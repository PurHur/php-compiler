--TEST--
stdlib iterator_to_array() — Generator from :Generator closure (ext/standard/array.c, #16141)
--FILE--
<?php
declare(strict_types=1);

$gen = function (): Generator {
    yield 1;
    yield 2;
};

$fromClosure = (function (Generator $g): array {
    return iterator_to_array($g);
})($gen());

$topLevel = iterator_to_array($gen());

var_export($fromClosure);
echo "\n";
var_export($topLevel);
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
)
array (
  0 => 1,
  1 => 2,
)
