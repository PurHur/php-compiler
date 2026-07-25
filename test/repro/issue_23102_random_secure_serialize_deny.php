<?php

/**
 * Repro for #23102 — Random\Engine\Secure serialize deny (php-src ext/random/random.stub.php).
 */
$secure = new Random\Engine\Secure();
try {
    serialize($secure);
    echo "Secure serialize:no-throw\n";
} catch (Throwable $e1) {
    echo 'Secure serialize ', get_class($e1), ':', $e1->getMessage(), "\n";
}

$randomizer = new Random\Randomizer(new Random\Engine\Secure());
try {
    serialize($randomizer);
    echo "Randomizer+Secure serialize:no-throw\n";
} catch (Throwable $e2) {
    echo 'Randomizer+Secure serialize ', get_class($e2), ':', $e2->getMessage(), "\n";
}

try {
    unserialize('O:20:"Random\Engine\Secure":0:{}');
    echo "Secure unserialize:no-throw\n";
} catch (Throwable $e3) {
    echo 'Secure unserialize ', get_class($e3), ':', $e3->getMessage(), "\n";
}

$mt = new Random\Engine\Mt19937(1);
try {
    $payload = serialize($mt);
    echo 'Mt19937 serialize:ok len=', strlen($payload), "\n";
} catch (Throwable $e4) {
    echo 'Mt19937 serialize ', get_class($e4), ':', $e4->getMessage(), "\n";
}
