--TEST--
stdlib str_shuffle() JIT (issue #968)
--FILE--
<?php
declare(strict_types=1);

function shuffle_sig(string $s): string
{
    $parts = str_split($s);
    sort($parts);
    return implode('', $parts);
}

echo shuffle_sig('abc') === shuffle_sig(str_shuffle('abc')) ? 'perm' : 'bad', "\n";
--EXPECT--
perm
