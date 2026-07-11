--TEST--
iterator_to_array() on : Generator closure — VM must not enforce Generator return on body completion (#16141)
--FILE--
<?php

declare(strict_types=1);

$gen = function (): Generator {
    yield 1;
    yield 2;
};

$fromClosure = (static function (Generator $g): array {
    return iterator_to_array($g);
})($gen());

$topLevel = iterator_to_array($gen());

echo 'from_closure=', var_export($fromClosure, true), "\n";
echo 'top_level=', var_export($topLevel, true), "\n";

if ($fromClosure !== [1, 2] || $topLevel !== [1, 2]) {
    exit(1);
}
--EXPECT--
from_closure=array (
  0 => 1,
  1 => 2,
)
top_level=array (
  0 => 1,
  1 => 2,
)
