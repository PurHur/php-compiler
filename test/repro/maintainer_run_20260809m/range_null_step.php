<?php
// #29352 — range(..., null) $step: Zend DEP then ValueError cannot be 0 (not TypeError)
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    fwrite(STDERR, "ERR[$errno]: $errstr\n");

    return true;
});
try {
    var_export(range(0, 2, null));
    echo "\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
}
