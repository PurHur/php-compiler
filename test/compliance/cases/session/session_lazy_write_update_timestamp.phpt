--TEST--
session session.lazy_write calls update_timestamp when $_SESSION unchanged (#21156, ext/session)
--FILE--
<?php
declare(strict_types=1);

$wrote = 0;
$upd = 0;
$store = [];

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
    function (string $id, string $data) use (&$store, &$wrote) {
        ++$wrote;
        $store[$id] = $data;

        return true;
    },
    function (string $id) {
        return true;
    },
    function (int $max_lifetime) {
        return 0;
    },
    function () {
        return 'ABCDEFGHIJKLMNOPQRSTUVWX12';
    },
    function (string $id) {
        return true;
    },
    function (string $id, string $data) use (&$upd) {
        ++$upd;

        return true;
    }
);
session_start(['lazy_write' => 1]);
$_SESSION['k'] = 1;
session_write_close();
session_start(['lazy_write' => 1]);
session_write_close();
echo $wrote, "\n", $upd, "\n";
--EXPECT--
1
1
