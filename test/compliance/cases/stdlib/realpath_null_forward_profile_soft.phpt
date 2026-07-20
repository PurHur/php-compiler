--TEST--
stdlib realpath(null) — soft-null DEP+coerce on 8.4 forward profile (#20362, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $n, string $m): bool {
    if (E_DEPRECATED === $n) {
        echo "DEP\n";

        return true;
    }

    return false;
});
try {
    $realNull = realpath(null);
    $realEmpty = realpath('');
    echo 'ok:', \gettype($realNull), ':', ($realNull === $realEmpty ? 'match' : 'mismatch'), "\n";
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
--EXPECT--
DEP
ok:string:match
