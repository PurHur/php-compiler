<?php

// #18943 — filter_var(null filter) Warning + false on default profile (ext/filter/filter.c).
$r = filter_var('x', null);
if (false !== $r) {
    echo 'fail: expected false, got ', var_export($r, true), "\n";
    exit(1);
}

echo "ok\n";
