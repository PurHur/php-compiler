--TEST--
stdlib session_cache_limiter(null) getter under strict_types (#30396, ext/session/session.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$dep = 0;
set_error_handler(static function (int $no, string $msg) use (&$dep): bool {
    if (E_DEPRECATED === $no) {
        $dep++;
        echo "DEP\n";
        return true;
    }
    return false;
});
try {
    var_export(session_cache_limiter(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo $dep === 0 ? "NO_DEP\n" : "HAD_DEP\n";
--EXPECT--
'nocache'
NO_DEP
