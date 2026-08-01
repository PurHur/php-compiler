--TEST--
stdlib str_increment()/str_decrement(null) soft-null then ValueError empty on 8.4 (#26264, re-#24179, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$depr = 0;
set_error_handler(static function (int $errno, string $errstr) use (&$depr): bool {
    if (E_DEPRECATED === $errno && str_contains($errstr, 'Passing null')) {
        ++$depr;

        return true;
    }

    return false;
});
foreach (['str_increment', 'str_decrement'] as $f) {
    $before = $depr;
    try {
        $f(null);
        echo $f, " COERCED\n";
    } catch (TypeError $e) {
        echo $f, " TypeError\n";
    } catch (ValueError $e) {
        $empty = str_contains($e->getMessage(), 'must not be empty') ? 'empty' : 'other';
        echo $f, ' ValueError ', $empty, ' depr=', $depr - $before, "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
try {
    str_increment('');
    echo "empty COERCED\n";
} catch (ValueError $e) {
    echo "empty ValueError\n";
}
--EXPECT--
str_increment ValueError empty depr=1
str_decrement ValueError empty depr=1
empty ValueError
