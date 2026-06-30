<?php

declare(strict_types=1);

if (1 !== preg_match('/(a)\K(b)/', 'ab', $m)) {
    echo "fail: preg_match returned no match\n";
    exit(1);
}

if ($m !== ['b', 'a', 'b']) {
    echo 'fail: matches='.json_encode($m)." expected [\"b\",\"a\",\"b\"]\n";
    exit(1);
}

$replaced = preg_replace('/(a)\K(b)/', 'X', 'ab');
if ('aX' !== $replaced) {
    echo "fail: preg_replace={$replaced} expected aX\n";
    exit(1);
}

echo "ok\n";
