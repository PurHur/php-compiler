--TEST--
stdlib http_response_code(null) — soft-null DEP+coerce on PROFILE=8.4 outside strict_types (#30019 / #21480)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
try {
    var_export(http_response_code(null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
http_response_code(404);
try {
    var_export(http_response_code(null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
var_export(http_response_code());
echo " get-ok\n";
try {
    http_response_code([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo 'depr=', (int) ($seen >= 1), "\n";
--EXPECT--
false
404
404 get-ok
http_response_code(): Argument #1 ($response_code) must be of type int, array given
depr=1
