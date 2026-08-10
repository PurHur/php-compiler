--TEST--
stdlib implode(null)/join(null) dual-arg TypeError JIT on PROFILE=8.4 (#29591)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

set_error_handler(static function (int $severity, string $message): bool {
    if (E_DEPRECATED === $severity) {
        echo 'Deprecated: ', $message, "\n";

        return true;
    }

    return false;
});

foreach (['implode', 'join'] as $fn) {
    try {
        $fn(null);
        echo $fn, "(null) => uncaught\n";
    } catch (Throwable $t) {
        echo $fn, '(null) => ', get_class($t), ': ', $t->getMessage(), "\n";
    }
}

try {
    $joined = implode(null, ['a', 'b']);
    echo 'implode(null, ["a","b"]) => ', var_export($joined, true), "\n";
} catch (Throwable $t) {
    echo 'implode(null, ["a","b"]) => ', get_class($t), ': ', $t->getMessage(), "\n";
}
--EXPECT--
implode(null) => TypeError: implode(): If argument #1 ($separator) is of type string, argument #2 ($array) must be of type array, null given
Deprecated: join(): Passing null to parameter #1 ($separator) of type array|string is deprecated
join(null) => TypeError: join(): If argument #1 ($separator) is of type string, argument #2 ($array) must be of type array, null given
Deprecated: implode(): Passing null to parameter #1 ($separator) of type array|string is deprecated
implode(null, ["a","b"]) => 'ab'
