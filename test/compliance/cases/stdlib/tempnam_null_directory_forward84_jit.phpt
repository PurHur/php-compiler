--TEST--
stdlib tempnam(null, 'x') JIT — DEP+system temp on 8.4 forward profile (#21595)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
function tempnam_null_dir_dep_handler_jit(int $no, string $msg): bool
{
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null to parameter #1 ($directory)')) {
        echo "DEP\n";
        return true;
    }
    return false;
}
set_error_handler('tempnam_null_dir_dep_handler_jit');
try {
    $p = tempnam(null, 'x');
    echo is_string($p) ? "path\n" : "fail\n";
    if (is_string($p)) {
        @unlink($p);
    }
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
DEP
path
