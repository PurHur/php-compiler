<?php
declare(strict_types=1);

$optind = 0;
$r = getopt('a:', [], $optind);
if (!\is_array($r) || !isset($r['a']) || '1' !== $r['a']) {
    echo 'fail: parse', "\n";
    exit(1);
}
if ($optind < 1) {
    echo 'fail: optind=', $optind, "\n";
    exit(1);
}
echo "ok\n";
