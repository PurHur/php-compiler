--TEST--
stdlib shuffle() on string list arrays (#2310)
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

$routes = ['b', 'a', 'c'];
$sig = list_sig($routes);
shuffle($routes);
echo list_sig($routes) === $sig ? 'perm' : 'bad', "\n";
echo count($routes), "\n";
--EXPECT--
perm
3
