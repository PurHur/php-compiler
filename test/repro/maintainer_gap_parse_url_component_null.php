<?php
/**
 * parse_url($url, null): Zend DEP+coerce component→0 (PHP_URL_SCHEME → 'http'); was TypeError (#24942).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if ($no === E_DEPRECATED) {
        echo "DEP: $str\n";
        return true;
    }
    return false;
});

$component = null;
try {
    $r = parse_url('http://example.com/x', $component);
    echo 'result=', var_export($r, true), ' type=', gettype($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $r2 = parse_url(url: 'http://example.com/x', component: $component);
    echo 'named=', var_export($r2, true), "\n";
} catch (Throwable $e) {
    echo 'named ', get_class($e), ': ', $e->getMessage(), "\n";
}
