<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo "W:$str\n";

    return true;
});
var_export(unpack('Q', 'xxxx'));
echo "\n";
