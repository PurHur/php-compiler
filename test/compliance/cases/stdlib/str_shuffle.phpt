--TEST--
stdlib str_shuffle() permutation (issue #968)
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
echo str_shuffle('a'), "\n";
echo strlen(str_shuffle('hello')), "\n";
echo shuffle_sig('hello') === shuffle_sig(str_shuffle('hello')) ? 'perm' : 'bad', "\n";
--EXPECT--
a
5
perm
