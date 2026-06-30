<?php

declare(strict_types=1);

preg_match('/(a)(b)?/', 'a', $m, PREG_UNMATCHED_AS_NULL);

if (!\array_key_exists(2, $m)) {
    echo "fail: capture group 2 missing from matches\n";
    exit(1);
}

if (null !== $m[2]) {
    echo 'fail: group 2=' . json_encode($m[2]) . " expected null\n";
    exit(1);
}

preg_match('/(a)(b)?/', 'a', $m2, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
if (!\array_key_exists(2, $m2) || !\is_array($m2[2]) || 2 !== \count($m2[2])) {
    echo "fail: offset capture group 2 shape wrong\n";
    exit(1);
}
if (null !== $m2[2][0] || -1 !== $m2[2][1]) {
    echo 'fail: group 2 offset=' . json_encode($m2[2]) . " expected [null,-1]\n";
    exit(1);
}

echo "ok\n";
