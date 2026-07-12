<?php

$r = count_chars(null);
if (!is_array($r) || 256 !== count($r) || 0 !== $r[0]) {
    fwrite(STDERR, 'count_chars(null): unexpected ' . var_export($r, true) . "\n");
    exit(1);
}
echo "count_chars: ok\n";

if (0 !== strspn('abc', null)) {
    fwrite(STDERR, "strspn('abc', null): expected 0\n");
    exit(1);
}
echo "strspn: ok\n";

if (3 !== strcspn('abc', null)) {
    fwrite(STDERR, "strcspn('abc', null): expected 3\n");
    exit(1);
}
echo "strcspn: ok\n";
