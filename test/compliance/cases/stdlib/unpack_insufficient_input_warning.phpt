--TEST--
stdlib unpack() insufficient-input warning need/have counts (#10958, ext/standard/pack.c)
--FILE--
<?php
declare(strict_types=1);

$data = pack('C', 1);
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});
$r = unpack('C2', $data);
echo $r === false ? "false\n" : "bad\n";
--EXPECT--
W:unpack(): Type C: not enough input, need 1, have 0
false
