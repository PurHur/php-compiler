--TEST--
stdlib inline Closure handlers after touch() — set_error_handler/register_shutdown_function (#17845, ext/standard/stat.c)
--FILE--
<?php

$p = sys_get_temp_dir() . '/phpc_touch_inline_handler_' . uniqid('', true) . '.tmp';
touch($p, 1);
set_error_handler(static fn (): bool => true);
register_shutdown_function(static function (): void {});
echo "ok\n";
@unlink($p);
--EXPECT--
ok
