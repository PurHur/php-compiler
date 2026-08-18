--TEST--
str_shuffle named string (JIT, issue #23919)
--FILE--
<?php
function shuffle_sig(string $s): string
{
    $parts = str_split($s);
    sort($parts);
    return implode('', $parts);
}
$named = str_shuffle(string: 'ab');
echo strlen($named), PHP_EOL;
echo shuffle_sig('ab') === shuffle_sig($named) ? 'perm' : 'bad', PHP_EOL;
try {
    str_shuffle(str: 'ab');
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
2
perm
Unknown named parameter $str
