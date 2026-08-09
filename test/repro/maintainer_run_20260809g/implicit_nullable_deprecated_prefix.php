<?php
/** Repro for #29274 — implicit nullable E_DEPRECATED must include f(): prefix (Zend 8.4). */
error_reporting(E_ALL);
$errors = [];
set_error_handler(static function (int $errno, string $msg) use (&$errors): bool {
    if ($errno === E_DEPRECATED) {
        $errors[] = $msg;
    }
    return true;
});
eval('function f(int $x = null): void {}');
foreach ($errors as $msg) {
    echo $msg, "\n";
}
