--TEST--
stdlib putenv(null) — soft-null DEP then ValueError on 8.4 (#21312, reverts #21004)
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
    putenv(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (ValueError $e) {
    echo ($deps > 0 ? 'DEP ' : ''), 'ValueError: ', $e->getMessage(), "\n";
}

try {
    putenv('');
    echo "empty: uncaught\n";
} catch (ValueError $e) {
    echo 'empty: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP ValueError: putenv(): Argument #1 ($assignment) must have a valid syntax
empty: putenv(): Argument #1 ($assignment) must have a valid syntax
