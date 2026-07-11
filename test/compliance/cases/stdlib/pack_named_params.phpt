--TEST--
stdlib pack() variadic named — ArgumentCountError (#13375, ext/standard/pack.c)
--FILE--
<?php
try {
    pack(format: 'N', values: 1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', bin2hex(pack('N', 1)), "\n";
?>
--EXPECT--
ArgumentCountError: pack() does not accept unknown named parameters
ok=00000001
