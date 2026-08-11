<?php
set_error_handler(static function (int $errno, string $message): bool {
    echo $message, PHP_EOL;

    return true;
});
foreach ([false, true] as $v) {
    $r = compact($v);
    echo 'return:', json_encode($r), PHP_EOL;
}
