--TEST--
Language: read property on null — JIT E_WARNING + null (#10381; zend_fetch.c)
--JIT--
--FILE--
<?php
set_error_handler(static function (int $errno, string $message): bool {
    if (E_WARNING === $errno) {
        echo 'W:', $message, "\n";
    }

    return true;
});

try {
    $x = null;
    $y = $x->prop;
    echo "no throw\n";
} catch (Error $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
--EXPECT--
W:Attempt to read property "prop" on null
no throw
