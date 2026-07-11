--TEST--
language: Generator forwarded through IIFE typed closure — iterator_to_array() (#16263, zend_closures.c)
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
