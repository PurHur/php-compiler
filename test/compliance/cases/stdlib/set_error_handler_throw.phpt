--TEST--
Stdlib: set_error_handler() throwing Error converts warning (#14106, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
set_error_handler(static function (): bool {
    throw new Error('handler');
});
try {
    $x = $undefined;
    echo "fail\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
restore_error_handler();
echo "ok\n";
--EXPECT--
handler
ok
