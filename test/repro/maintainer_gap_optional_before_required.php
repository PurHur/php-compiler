<?php
/** Repro #31904 — optional-before-required E_DEPRECATED (zend_compile.c) */
error_reporting(E_ALL);
$errors = [];
set_error_handler(static function (int $errno, string $msg) use (&$errors): bool {
    $errors[] = $msg;
    return true;
});

eval('
function f($a = 1, $b) {}
class C {
    public function m($a = 1, $b) {}
}
$cl = function ($a = 1, $b) {};
$ar = fn($a = 1, $b) => 1;
');

foreach ($errors as $msg) {
    echo $msg, "\n";
}

try {
    f(b: 2);
    echo "FAIL: expected ArgumentCountError\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
echo "ok\n";
