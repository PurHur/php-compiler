<?php

declare(strict_types=1);

$a = [1];
$a[1] = &$a[0];
$blob = serialize($a);
if (!str_contains($blob, 'R:')) {
    echo "fail: missing R: in array ref\n";
    exit(1);
}
if ($blob !== 'a:2:{i:0;i:1;i:1;R:2;}') {
    echo "fail: array blob=$blob\n";
    exit(1);
}

$s = 'x';
$blob2 = serialize([&$s, &$s]);
if (!str_contains($blob2, 'R:')) {
    echo "fail: missing R: in string ref\n";
    exit(1);
}
if ($blob2 !== 'a:2:{i:0;s:1:"x";i:1;R:2;}') {
    echo "fail: string blob=$blob2\n";
    exit(1);
}

$round = unserialize($blob);
if (!\is_array($round) || $round[0] !== $round[1]) {
    echo "fail: roundtrip values\n";
    exit(1);
}
$round[0] = 9;
if (9 !== $round[1]) {
    echo "fail: roundtrip alias\n";
    exit(1);
}

echo "ok\n";
