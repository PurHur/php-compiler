<?php

error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }

    return true;
});
echo 'result=', var_export(mb_convert_encoding(null, 'UTF-8', 'UTF-8'), true), "\n";
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 1), "\n";
if ($seen !== []) {
    echo 'msg=', $seen[0], "\n";
}
