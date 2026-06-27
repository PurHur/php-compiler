<?php

declare(strict_types=1);

// php-src ext/standard/var.c — self-ref array R: marker index (#12825).
$a = [];
$a[0] = &$a;
$blob = serialize($a);
$expected = 'a:1:{i:0;a:1:{i:0;R:2;}}';
if ($blob !== $expected) {
    echo "fail: blob=$blob expected=$expected\n";
    exit(1);
}

echo "ok\n";
