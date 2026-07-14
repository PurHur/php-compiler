<?php

// #18943 — filter_var(null filter) Warning + false on default profile (not TypeError).
$r = @filter_var('x', null);
if (false !== $r) {
    echo 'fail filter_var: expected false, got ', var_export($r, true), "\n";
    exit(1);
}
$_GET['q'] = 'x';
$r2 = @filter_input(INPUT_GET, 'q', null);
if (false !== $r2) {
    echo 'fail filter_input: expected false, got ', var_export($r2, true), "\n";
    exit(1);
}

echo "ok\n";
