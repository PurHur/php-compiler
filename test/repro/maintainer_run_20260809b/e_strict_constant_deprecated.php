<?php
error_reporting(E_ALL);
$msgs = [];
set_error_handler(static function (int $n, string $m) use (&$msgs): bool {
    $msgs[] = $m;
    return true;
});
echo 'val=', E_STRICT, "\n";
echo 'warns=', json_encode($msgs), "\n";
