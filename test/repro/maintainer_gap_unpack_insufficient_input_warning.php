<?php
declare(strict_types=1);

$data = pack('C', 1);
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});
$r = unpack('C2', $data);
echo $r === false ? "false\n" : "bad\n";

$data2 = pack('N', 1);
$r2 = unpack('N2', $data2);
echo $r2 === false ? "false2\n" : "bad2\n";
