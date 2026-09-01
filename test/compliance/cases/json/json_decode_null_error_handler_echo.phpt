--TEST--
json json_decode(null) inside echo var_export — E_DEPRECATED reaches set_error_handler (#21223)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
echo var_export(json_decode(null), true), "\n";
echo 'depr=', (int) ($seen >= 1), "\n";
?>
--EXPECT--
NULL
depr=1
