<?php
/** Repro #21781 — token_get_all(null) deprecation cites parameter #1 ($code). */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo $msg, "\n";

        return true;
    }

    return false;
});
$code = null;
echo 'count=', count(token_get_all($code)), "\n";
