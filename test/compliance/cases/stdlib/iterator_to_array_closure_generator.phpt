--TEST--
iterator_to_array() on IIFE generator inside closure (#10731)
--FILE--
<?php

declare(strict_types=1);

$fromClosure = (static function (): array {
    return iterator_to_array((static function (): Generator {
        yield 1;
        yield 2;
    })());
})();

$topLevel = iterator_to_array((static function (): Generator {
    yield 1;
    yield 2;
})());

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
