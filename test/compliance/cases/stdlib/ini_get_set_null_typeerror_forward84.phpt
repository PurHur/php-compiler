--TEST--
stdlib ini_get()/ini_set(null) — soft-null DEP+coerce on 8.4 (#21312, reverts #20361)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        ++$deps;
    }
    return true;
});
try {
    $r = ini_get(null);
    echo 'ini_get', ($deps > 0 ? ' DEP' : ''), ' ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'ini_get ', get_class($e), "\n";
}
$deps = 0;
try {
    $r = ini_set(null, '1');
    echo 'ini_set', ($deps > 0 ? ' DEP' : ''), ' ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'ini_set ', get_class($e), "\n";
}
?>
--EXPECT--
ini_get DEP false
ini_set DEP false
