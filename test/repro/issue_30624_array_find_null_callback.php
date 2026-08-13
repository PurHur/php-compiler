<?php
// Repro #30624 — null $callback on array_find family must match Zend TypeError (not VM::invokePhpFunction).
// Direct calls so AOT lowers through Internal::call + catchable ExceptionBridge (#17133).
try {
    array_find([1], null);
    echo "array_find uncaught\n";
} catch (Throwable $e) {
    echo 'array_find: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_find_key(['a' => 1], null);
    echo "array_find_key uncaught\n";
} catch (Throwable $e) {
    echo 'array_find_key: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_any([1], null);
    echo "array_any uncaught\n";
} catch (Throwable $e) {
    echo 'array_any: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_all([1], null);
    echo "array_all uncaught\n";
} catch (Throwable $e) {
    echo 'array_all: ', get_class($e), ': ', $e->getMessage(), "\n";
}
