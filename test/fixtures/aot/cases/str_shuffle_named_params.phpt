--TEST--
AOT: str_shuffle() named string: argument (#23919)
--FILE--
<?php
function shuffle_sig(string $s): string
{
    $parts = str_split($s);
    sort($parts);
    return implode('', $parts);
}
$named = str_shuffle(string: 'ab');
echo strlen($named), "\n";
echo shuffle_sig('ab') === shuffle_sig($named) ? 'perm' : 'bad', "\n";
--EXPECT--
2
perm
