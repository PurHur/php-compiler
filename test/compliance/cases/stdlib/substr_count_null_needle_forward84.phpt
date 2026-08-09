--TEST--
stdlib substr_count(null $needle) soft-null DEP then ValueError empty on 8.4 (#29421, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$depr = 0;
set_error_handler(static function (int $errno, string $errstr) use (&$depr): bool {
    if (E_DEPRECATED === $errno && str_contains($errstr, 'Passing null to parameter #2 ($needle)')) {
        ++$depr;

        return true;
    }

    return false;
});
try {
    substr_count('aaa', null);
    echo "null_uncaught\n";
} catch (TypeError $e) {
    echo "null TypeError\n";
} catch (ValueError $e) {
    $empty = str_contains($e->getMessage(), 'must not be empty') ? 'empty' : 'other';
    echo 'null ValueError ', $empty, ' depr=', $depr, "\n";
}
try {
    substr_count('aaa', '');
    echo "empty_uncaught\n";
} catch (ValueError $e) {
    echo "empty ValueError depr=", $depr, "\n";
}
--EXPECT--
null ValueError empty depr=1
empty ValueError depr=1
