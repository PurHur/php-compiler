<?php

declare(strict_types=1);

$store = [];
$validated = [];
$created = 0;

ini_set('session.use_strict_mode', '1');
session_id('short');
session_set_save_handler(
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
    function (string $id) {
        return true;
    },
    function (int $max_lifetime) {
        return 0;
    },
    function () use (&$created) {
        ++$created;

        return 'ABCDEFGHIJKLMNOPQRSTUVWX12';
    },
    function (string $id) use (&$validated) {
        $validated[] = $id;

        return 26 === strlen($id);
    }
);
session_start();
$lines = [
    session_id(),
    (string) $created,
    implode(',', $validated),
];
session_write_close();
echo implode("\n", $lines), "\n";
