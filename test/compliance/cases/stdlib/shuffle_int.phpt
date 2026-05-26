--TEST--
stdlib shuffle() on integer list arrays (#2310)
--FILE--
<?php
function list_sig(array $a): string
{
    $parts = [];
    foreach ($a as $v) {
        $parts[] = (string) $v;
    }
    sort($parts);

    return implode(',', $parts);
}

$nums = [3, 1, 2];
$sig = list_sig($nums);
shuffle($nums);
echo list_sig($nums) === $sig ? 'perm' : 'bad', "\n";
--EXPECT--
perm
