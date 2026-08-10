--TEST--
stdlib http_response_code(null) — soft-null DEP+coerce JIT on PROFILE=8.4 outside strict_types (#30019 / #21480)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
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
echo 'depr=', (int) ($seen >= 1), "\n";
--EXPECT--
false
404
404 get-ok
depr=1
