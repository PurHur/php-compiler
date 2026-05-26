--TEST--
stdlib shuffle() in-place on a packed list (issue #2310)
--FILE--
<?php
function arr_sig(array $a): string
{
    $copy = $a;
    sort($copy);

    return implode(',', $copy);
}
$a = [1, 2, 3, 4];
$sig = arr_sig($a);
shuffle($a);
echo count($a), "\n";
echo arr_sig($a) === $sig ? 'perm' : 'bad', "\n";
--EXPECT--
4
perm
