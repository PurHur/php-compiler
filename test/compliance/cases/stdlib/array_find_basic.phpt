--TEST--
stdlib array_find / array_find_key / array_any / array_all
--FILE--
<?php
function gt2(int $v): bool
{
    return $v > 2;
}

$a = [1, 2, 3, 4];
echo array_find($a, fn ($v) => $v > 2), "\n";
echo array_find_key($a, fn ($v) => $v > 2), "\n";
echo array_any($a, fn ($v) => $v > 3) ? 'y' : 'n', "\n";
echo array_all($a, fn ($v) => $v > 0) ? 'y' : 'n', "\n";

$b = ['x' => 10, 'y' => 20];
echo array_find($b, fn ($v) => $v > 15), "\n";
echo array_find_key($b, fn ($v) => $v > 15), "\n";

echo array_find([1, 2], fn ($v) => $v > 5) === null ? 'null' : 'bad', "\n";
echo array_find_key([1, 2], fn ($v) => $v > 5) === null ? 'null' : 'bad', "\n";

echo array_any([], fn ($v) => true) ? 'y' : 'n', "\n";
echo array_all([], fn ($v) => true) ? 'y' : 'n', "\n";

echo array_find($a, 'gt2'), "\n";
--EXPECT--
3
2
y
y
20
y
null
null
n
y
3
