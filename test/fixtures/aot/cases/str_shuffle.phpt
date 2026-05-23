--TEST--
AOT: str_shuffle() permutation
--FILE--
<?php
function sorted_bytes(string $s): string
{
    $parts = str_split($s);
    sort($parts);

    return implode('', $parts);
}

echo str_shuffle('z'), "\n";
$in = '123abc';
$out = str_shuffle($in);
echo strlen($out), "\n";
echo sorted_bytes($out), "\n";
--EXPECT--
z
6
123abc
