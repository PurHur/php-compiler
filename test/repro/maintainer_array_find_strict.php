<?php

declare(strict_types=1);

/**
 * Maintainer repro: array_find()/array_find_key()/array_all()/array_any() optional $strict (#6949).
 */

$haystack = [1, '1', 2];

if (array_find($haystack, fn ($v) => $v == 1) !== 1) {
    echo "fail: loose array_find\n";
    exit(1);
}

$strictFind = array_find($haystack, fn ($v) => $v == 1 ? 1 : 0, true);
if (null !== $strictFind) {
    echo "fail: strict array_find truthy-int callback\n";
    exit(1);
}

if (array_find_key($haystack, fn ($v) => $v == 1) !== 0) {
    echo "fail: loose array_find_key\n";
    exit(1);
}

$strictFindKey = array_find_key($haystack, fn ($v) => $v == 1 ? 1 : 0, true);
if (null !== $strictFindKey) {
    echo "fail: strict array_find_key truthy-int callback\n";
    exit(1);
}

$h = ['a' => 1, 'b' => '1'];
if (!array_all($h, fn ($v, $k) => $v == 1, false)) {
    echo "fail: array_all loose\n";
    exit(1);
}
if (!array_all($h, fn ($v, $k) => $v == 1 ? 1 : 0, false)) {
    echo "fail: array_all loose truthy-int\n";
    exit(1);
}
if (array_all($h, fn ($v, $k) => $v == 1 ? 1 : 0, true)) {
    echo "fail: array_all strict truthy-int\n";
    exit(1);
}
if (!array_any($h, fn ($v, $k) => $v == 1 ? 1 : 0, false)) {
    echo "fail: array_any loose truthy-int\n";
    exit(1);
}
if (array_any($h, fn ($v, $k) => $v == 1 ? 1 : 0, true)) {
    echo "fail: array_any strict truthy-int\n";
    exit(1);
}

echo "ok\n";
