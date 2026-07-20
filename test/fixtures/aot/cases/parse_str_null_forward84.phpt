--TEST--
AOT: parse_str(null) — E_DEPRECATED + empty result on 8.4 forward profile (#21223)
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
parse_str(null, $o);
echo var_export($o, true), "\n";
echo 'depr=', (int) ($seen >= 1), "\n";
?>
--EXPECT--
array (
)
depr=1
