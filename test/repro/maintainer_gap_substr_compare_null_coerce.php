<?php

$result = substr_compare(null, 'a', 0);
if (-1 !== $result) {
    fwrite(STDERR, "substr_compare(null, 'a', 0): expected -1, got $result\n");
    exit(1);
}

$ncmp = strncmp(null, 'a', 1);
if (-1 !== $ncmp) {
    fwrite(STDERR, "strncmp(null, 'a', 1): expected -1, got $ncmp\n");
    exit(1);
}

echo "ok\n";
