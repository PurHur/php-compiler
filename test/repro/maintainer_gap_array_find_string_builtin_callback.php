<?php
// Issue #13946 — array_find family rejects string builtin callbacks (php-src passes value+key).
$ok = true;
$calls = [
    static fn () => array_any([1, 2, 3], 'strlen'),
    static fn () => array_all([1, 2, 3], 'is_int'),
    static fn () => array_find([1, 2, 3], 'is_int'),
    static fn () => array_find_key([1, 2, 3], 'is_int'),
];
foreach ($calls as $call) {
    try {
        $call();
        $ok = false;
        echo "expected Error, got success\n";
    } catch (\Error $e) {
        // ArgumentCountError extends Error — php-src parity.
    } catch (\Throwable $e) {
        $ok = false;
        echo 'unexpected ', \get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo $ok ? "ok\n" : "fail\n";
