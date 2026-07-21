<?php
// Guard #21668 — ord(null) deprecation cites parameter #1 ($character) under PROFILE=8.4
// Use a variable so compile-time host fold cannot mask the VM emit path.
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo $msg, "\n";

        return true;
    }

    return false;
});
$character = null;
echo var_export(ord($character), true), "\n";
$codepoint = null;
echo var_export(chr($codepoint), true), "\n";
