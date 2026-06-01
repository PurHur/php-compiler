--TEST--
stdlib dl() — E_WARNING when enable_dl off (issue #3779)
--FILE--
<?php
function dl_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('dl_warn_capture');
$r = dl('nonexistent.so');
var_dump($r);
--EXPECT--
W:Dynamically loaded extensions aren't enabled
bool(false)
