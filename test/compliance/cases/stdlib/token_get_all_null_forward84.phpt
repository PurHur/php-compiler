--TEST--
token_get_all(null) soft-null DEP+empty array on 8.4 (#21503, reverts #19894)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if ($no === E_DEPRECATED) {
        ++$deps;
    }
    return true;
});
try {
    $r = token_get_all(null);
    echo ($deps > 0 ? 'DEP ' : ''), 'count=', count($r), "\n";
} catch (\Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP count=0
