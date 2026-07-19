--TEST--
session session_set_save_handler() 6-callback form — open/close/read/write/destroy/gc (#21136, ext/session/session.c)
--FILE--
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
$module = session_module_name();
$has = isset($_SESSION['k']) ? 'has-k' : 'no-k';
$val = (string) ($_SESSION['k'] ?? 'missing');
session_write_close();

$argcMsg = '';
try {
    session_set_save_handler(
        function () { return true; },
        function () { return true; },
        function () { return ''; },
        function () { return true; }
    );
    $argcMsg = 'argc4-uncaught';
} catch (ArgumentCountError $e) {
    $argcMsg = $e->getMessage();
}

$cbMsg = '';
try {
    session_set_save_handler(
        'not_a_session_cb',
        function () { return true; },
        function () { return ''; },
        function () { return true; },
        function () { return true; },
        function () { return true; }
    );
    $cbMsg = 'badcb-uncaught';
} catch (TypeError $e) {
    $cbMsg = $e->getMessage();
}

echo implode("\n", [
    $ok ? 'true' : 'false',
    $has,
    $val,
    $module,
    $argcMsg,
    $cbMsg,
]), "\n";
--EXPECT--
true
has-k
42
user
Wrong parameter count for session_set_save_handler()
session_set_save_handler(): Argument #1 ($open) must be a valid callback, function "not_a_session_cb" not found or invalid function name
