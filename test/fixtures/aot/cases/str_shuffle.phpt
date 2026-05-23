--TEST--
AOT: str_shuffle() permutation (issue #968)
--FILE--
<?php
declare(strict_types=1);

function shuffle_sig(string $s): string
{
    $parts = str_split($s);
    sort($parts);
    return implode('', $parts);
}

echo str_shuffle(''), "\n";
echo str_shuffle('x'), "\n";
echo shuffle_sig('abcd') === shuffle_sig(str_shuffle('abcd')) ? 'perm' : 'bad', "\n";
--EXPECT--
x
perm
