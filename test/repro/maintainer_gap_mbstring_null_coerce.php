<?php

if (0 !== mb_strlen(null)) {
    fwrite(STDERR, 'mb_strlen(null): expected 0' . "\n");
    exit(1);
}
echo "mb_strlen: ok\n";

if ('' !== mb_substr(null, 0)) {
    fwrite(STDERR, "mb_substr(null, 0): expected ''\n");
    exit(1);
}
echo "mb_substr: ok\n";

if ('' !== mb_strtolower(null)) {
    fwrite(STDERR, "mb_strtolower(null): expected ''\n");
    exit(1);
}
echo "mb_strtolower: ok\n";
