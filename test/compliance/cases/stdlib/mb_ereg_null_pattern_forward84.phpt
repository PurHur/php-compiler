--TEST--
stdlib mb_ereg()/mb_eregi(null) — soft-DEP then empty ValueError on 8.4 (#30067, reverts #20261 TypeError claim)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo 'DEP:', $msg, "\n";
        return true;
    }
    return false;
});
foreach (['mb_ereg', 'mb_eregi'] as $fn) {
    try {
        $fn(null, 'abc');
        echo "$fn null: uncaught\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
    try {
        $fn('', 'abc');
        echo "$fn empty: uncaught\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
DEP:mb_ereg(): Passing null to parameter #1 ($pattern) of type string is deprecated
ValueError: mb_ereg(): Argument #1 ($pattern) must not be empty
mb_ereg(): Argument #1 ($pattern) must not be empty
DEP:mb_eregi(): Passing null to parameter #1 ($pattern) of type string is deprecated
ValueError: mb_eregi(): Argument #1 ($pattern) must not be empty
mb_eregi(): Argument #1 ($pattern) must not be empty
