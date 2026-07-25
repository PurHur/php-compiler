--TEST--
Random\Randomizer() defaults to Secure; explicit Mt19937 still serializes (issue #23163, ext/random/randomizer.c)
--FILE--
<?php
$default = new Random\Randomizer();
try {
    serialize($default);
    echo "default serialize:no-throw\n";
} catch (Throwable $e1) {
    echo get_class($e1), ':', $e1->getMessage(), "\n";
}

$mt = new Random\Randomizer(new Random\Engine\Mt19937(42));
try {
    $payload = serialize($mt);
    $round = unserialize($payload);
    echo ($round instanceof Random\Randomizer && is_string($payload))
        ? "Mt19937 Randomizer serialize:ok\n"
        : "Mt19937 Randomizer serialize:bad\n";
} catch (Throwable $e2) {
    echo get_class($e2), ':', $e2->getMessage(), "\n";
}

$explicitSecure = new Random\Randomizer(new Random\Engine\Secure());
try {
    serialize($explicitSecure);
    echo "explicit Secure serialize:no-throw\n";
} catch (Throwable $e3) {
    echo get_class($e3), ':', $e3->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'Random\Engine\Secure' is not allowed
Mt19937 Randomizer serialize:ok
Exception:Serialization of 'Random\Engine\Secure' is not allowed
