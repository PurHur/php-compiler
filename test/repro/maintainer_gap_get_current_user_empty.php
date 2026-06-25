<?php
// Issue #11755 — get_current_user() returns '' for stdin (no script path).
$user = get_current_user();
if ('' !== $user) {
    echo "fail: expected empty string, got '{$user}'\n";
    exit(1);
}
echo "ok\n";
