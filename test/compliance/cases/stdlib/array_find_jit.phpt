--TEST--
stdlib array_find family JIT/AOT (#3073)
--FILE--
<?php
function gt2(int $v): bool
{
    return $v > 2;
}

$a = [1, 2, 3, 4];
echo array_find($a, fn ($v) => $v > 2), "\n";
echo array_find($a, 'gt2'), "\n";
--EXPECT--
3
3
