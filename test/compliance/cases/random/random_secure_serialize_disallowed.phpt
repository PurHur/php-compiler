--TEST--
Random\Engine\Secure serialize()/unserialize() reject; Mt19937 still allowed (issue #23102, ext/random/random.stub.php)
--FILE--
<?php
$secure = new Random\Engine\Secure();
try {
    serialize($secure);
    echo "Secure serialize:no-throw\n";
} catch (Throwable $e1) {
    echo get_class($e1), ':', $e1->getMessage(), "\n";
}

$randomizer = new Random\Randomizer(new Random\Engine\Secure());
try {
    serialize($randomizer);
    echo "Randomizer+Secure serialize:no-throw\n";
} catch (Throwable $e2) {
    echo get_class($e2), ':', $e2->getMessage(), "\n";
}

try {
    unserialize('O:20:"Random\Engine\Secure":0:{}');
    echo "Secure unserialize:no-throw\n";
} catch (Throwable $e3) {
    echo get_class($e3), ':', $e3->getMessage(), "\n";
}

$mt = new Random\Engine\Mt19937(1);
try {
    $payload = serialize($mt);
    echo 'Mt19937 serialize:ok', "\n";
    $round = unserialize($payload);
    echo ($round instanceof Random\Engine\Mt19937) ? "Mt19937 unserialize:ok\n" : "Mt19937 unserialize:bad\n";
} catch (Throwable $e4) {
    echo get_class($e4), ':', $e4->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'Random\Engine\Secure' is not allowed
Exception:Serialization of 'Random\Engine\Secure' is not allowed
Exception:Unserialization of 'Random\Engine\Secure' is not allowed
Mt19937 serialize:ok
Mt19937 unserialize:ok
