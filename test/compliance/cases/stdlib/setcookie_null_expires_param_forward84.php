<?php
// Guard #21735 — setcookie(null $expires) deprecation cites parameter #3 ($expires_or_options)
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo $msg, "\n";

        return true;
    }

    return false;
});
$expires = null;
@setcookie('n', 'v', $expires);
