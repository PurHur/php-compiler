<?php

$prev = ignore_user_abort(true);
if (0 !== $prev && 1 !== $prev) {
    fwrite(STDERR, "expected prior 0 or 1, got: ".var_export($prev, true)."\n");
    exit(1);
}
$restored = ignore_user_abort($prev);
if (1 !== $restored && 0 !== $restored) {
    fwrite(STDERR, "expected restore return 0 or 1, got: ".var_export($restored, true)."\n");
    exit(1);
}
echo "ok\n";
