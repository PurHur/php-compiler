--TEST--
stdlib file_get_contents(http://) Connection refused errstr (#25288)
--FILE--
<?php
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    echo (str_contains($m, 'Connection refused') ? 'refused' : 'other')."\n";
    echo (str_contains($m, 'Failed to open stream') ? 'open_fail' : 'no_open')."\n";

    return true;
});

$r = @file_get_contents('http://127.0.0.1:9/');
echo false === $r ? "false\n" : "not_false\n";
--EXPECT--
refused
open_fail
false
