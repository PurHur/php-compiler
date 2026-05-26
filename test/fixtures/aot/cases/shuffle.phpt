--TEST--
AOT: shuffle() in-place permutation (issue #2310)
--FILE--
<?php
function arr_sig(array $a): string
{
    $copy = $a;
    sort($copy);

    return implode(',', $copy);
}
$a = [5, 6, 7, 8];
$sig = arr_sig($a);
shuffle($a);
echo count($a), "\n";
echo arr_sig($a) === $sig ? 'perm' : 'bad', "\n";
--EXPECT--
4
perm
