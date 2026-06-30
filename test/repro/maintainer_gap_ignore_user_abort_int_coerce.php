<?php

declare(strict_types=1);

$prev = ignore_user_abort(true);
if (0 !== $prev) {
    fwrite(STDERR, "expected prev=0, got {$prev}\n");
    exit(1);
}
$restored = ignore_user_abort($prev);
if (1 !== $restored) {
    fwrite(STDERR, "expected restored=1, got {$restored}\n");
    exit(1);
}
echo "ok\n";
