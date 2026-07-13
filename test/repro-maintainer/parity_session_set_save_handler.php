<?php

declare(strict_types=1);

class S {
    private static array $store = [];

    public function open($path, $name): bool { return true; }
    public function close(): bool { return true; }
    public function read($id): string { return self::$store[$id] ?? ''; }
    public function write($id, $data): bool { self::$store[$id] = $data; return true; }
    public function destroy($id): bool { unset(self::$store[$id]); return true; }
    public function gc($max): int|false { return 0; }
}

$s = new S();
session_set_save_handler($s, true);
session_register_shutdown();
session_start();
$_SESSION['k'] = 1;
session_write_close();
echo function_exists('session_set_save_handler') ? "true\n" : "false\n";
echo "ok\n";
