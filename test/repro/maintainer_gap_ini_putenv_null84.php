<?php
/**
 * #21312 — ini_get/ini_set/putenv(null) soft-null under PHP_COMPILER_PROFILE=8.4
 * (reverts #20361/#21004 TypeError; Zend Z_PARAM_STR DEP+coerce).
 */
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null')) {
        ++$deps;
        echo "DEP\n";
    }
    return true;
});

try {
    $r = ini_get(null);
    echo 'ini_get ', ($r === false ? 'false' : 'bad'), "\n";
} catch (Throwable $e) {
    echo 'ini_get ', get_class($e), "\n";
    exit(1);
}

try {
    $r = ini_set(null, '1');
    echo 'ini_set ', ($r === false ? 'false' : 'bad'), "\n";
} catch (Throwable $e) {
    echo 'ini_set ', get_class($e), "\n";
    exit(1);
}

try {
    putenv(null);
    echo "putenv uncaught\n";
    exit(1);
} catch (ValueError $e) {
    echo "putenv ValueError\n";
} catch (Throwable $e) {
    echo 'putenv ', get_class($e), "\n";
    exit(1);
}

echo $deps >= 3 ? "OK\n" : "MISSING_DEP deps={$deps}\n";
