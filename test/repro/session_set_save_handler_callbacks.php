<?php

declare(strict_types=1);

$store = [];
$ok = session_set_save_handler(
    function (string $path, string $name) {
        return true;
    },
    function () {
        return true;
    },
    function (string $id) use (&$store) {
        return $store[$id] ?? '';
    },
    function (string $id, string $data) use (&$store) {
        $store[$id] = $data;

        return true;
    },
    function (string $id) use (&$store) {
        unset($store[$id]);

        return true;
    },
    function (int $max_lifetime) {
        return 0;
    }
);

session_start();
$_SESSION['k'] = 42;
session_write_close();
session_start();
$lines = [
    $ok ? 'true' : 'false',
    isset($_SESSION['k']) ? 'has-k' : 'no-k',
    (string) ($_SESSION['k'] ?? 'missing'),
    session_module_name(),
];
session_write_close();
echo implode("\n", $lines), "\n";
