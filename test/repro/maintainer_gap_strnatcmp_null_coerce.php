<?php
$nat = strnatcmp(null, 'a');
$col = strcoll(null, 'a');
if (-1 !== $nat) {
    echo "fail: strnatcmp(null) expected -1 got {$nat}\n";
    exit(1);
}
if (-97 !== $col) {
    echo "fail: strcoll(null) expected -97 got {$col}\n";
    exit(1);
}
echo "ok\n";
