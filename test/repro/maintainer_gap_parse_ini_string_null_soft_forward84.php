<?php

error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }

    return true;
});
try {
    $r = parse_ini_string(null);
    if ([] !== $r) {
        echo 'bad_result=', var_export($r, true), "\n";
        exit(1);
    }
    if ([] === $seen) {
        echo "missing_deprecation\n";
        exit(1);
    }
    echo "OK parse_ini_string null soft-null forward84\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
    exit(1);
}
restore_error_handler();
