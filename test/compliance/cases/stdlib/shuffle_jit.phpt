--TEST--
stdlib shuffle() JIT (issue #2310)
--FILE--
<?php
function arr_sig(array $a): string
{
    $copy = $a;
    sort($copy);

    return implode(',', $copy);
}
$a = [10, 20, 30];
$sig = arr_sig($a);
shuffle($a);
echo arr_sig($a) === $sig ? 'perm' : 'bad', "\n";
--EXPECT--
perm
