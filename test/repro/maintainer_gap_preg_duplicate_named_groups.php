<?php

declare(strict_types=1);

if (1 !== preg_match('/(?|(?<n>a)|(?<n>b))/', 'b', $m)) {
    echo "fail: preg_match returned no match\n";
    exit(1);
}

if ($m['n'] !== 'b' || $m[0] !== 'b' || $m[1] !== 'b') {
    echo 'fail: matches=' . json_encode($m) . "\n";
    exit(1);
}

echo "ok\n";
