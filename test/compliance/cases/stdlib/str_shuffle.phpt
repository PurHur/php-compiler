--TEST--
stdlib str_shuffle()
--FILE--
<?php
function sorted_bytes(string $s): string
{
    $parts = str_split($s);
    sort($parts);

    return implode('', $parts);
}

echo strlen(str_shuffle('')), "\n";
echo str_shuffle('x'), "\n";
$in = 'aabbcc';
$out = str_shuffle($in);
echo strlen($out), "\n";
echo sorted_bytes($out), "\n";
echo sorted_bytes($in), "\n";
--EXPECT--
0
x
6
aabbcc
aabbcc
