--TEST--
Language: named argument for trailing parameter after variadic (#7411)
--FILE--
<?php
declare(strict_types=1);

function g(mixed ...$rest, int $b = 1): void
{
    echo $b, "\n";
}

g(b: 2);

function h(mixed ...$rest, int $b = 1): array
{
    return [$rest, $b];
}

var_export(h(1, b: 2));
echo "\n";
var_export(h(extra: 9, b: 2));
echo "\n";
--EXPECT--
2
array (
  0 => array (
    0 => 1,
  ),
  1 => 2,
)
array (
  0 => array (
    'extra' => 9,
  ),
  1 => 2,
)
