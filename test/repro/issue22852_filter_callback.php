<?php
declare(strict_types=1);

set_error_handler(static function ($n, $m) {
    fwrite(STDERR, "W:$m\n");

    return true;
});

try {
    $r = filter_var('abc', FILTER_CALLBACK, ['options' => function ($v) {
        return strtoupper($v);
    }]);
    var_export($r);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $r = filter_var('xyz', FILTER_CALLBACK, ['options' => 'strtoupper']);
    var_export($r);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    filter_var('x', FILTER_CALLBACK, ['options' => null]);
    echo "null OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
