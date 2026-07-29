<?php
/**
 * pathinfo($path, null): Zend DEP+'' (flags coerce to 0); was DEP+array() (#24941).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if ($no === E_DEPRECATED) {
        echo "DEP: $str\n";
        return true;
    }
    return false;
});

try {
    $r = pathinfo('/a/b.txt', null);
    echo 'result=', var_export($r, true), ' type=', gettype($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$z = pathinfo('/a/b.txt', 0);
echo 'flags0=', var_export($z, true), ' type=', gettype($z), "\n";
