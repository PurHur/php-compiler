--TEST--
session session_set_save_handler() / session_register_shutdown() — custom object handler (#4873, ext/session/mod_user.c)
--FILE--
<?php
declare(strict_types=1);

class TestSessionHandler implements SessionHandlerInterface, SessionIdInterface {
    private static array $store = [];

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        return self::$store[$id] ?? '';
    }

    public function write(string $id, string $data): bool
    {
        self::$store[$id] = $data;

        return true;
    }

    public function destroy(string $id): bool
    {
        unset(self::$store[$id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }
}

$handler = new TestSessionHandler();
session_set_save_handler($handler, true);
session_register_shutdown();
session_start();
$_SESSION['k'] = 1;
session_write_close();
session_start();
$module = session_module_name();
$lines = [
    function_exists('session_set_save_handler') ? 'true' : 'false',
    function_exists('session_register_shutdown') ? 'true' : 'false',
    $module,
    isset($_SESSION['k']) ? 'has-k' : 'no-k',
    (string) ($_SESSION['k'] ?? 'missing'),
];
session_write_close();
echo implode("\n", $lines), "\n";
--EXPECT--
true
true
user
has-k
1
