--TEST--
stdlib array_pad() negative length after prior UDF array param — haystack not clobbered (#16066, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

function hold(array $v): void
{
    json_encode($v);
}

hold([]);
$r = array_pad([1, 2], -4, 0);
echo var_export($r, true), "\n";
?>
--EXPECT--
array (
  0 => 0,
  1 => 0,
  2 => 1,
  3 => 2,
)
