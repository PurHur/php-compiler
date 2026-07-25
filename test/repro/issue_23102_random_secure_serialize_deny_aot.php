<?php

/**
 * AOT repro for #23102 — Random\Engine\Secure serialize deny (no Randomizer ctor).
 * Randomizer+Secure is covered by VM/JIT compliance (user-script AOT needs Mt19937 #19574).
 */
$secure = new Random\Engine\Secure();
try {
    serialize($secure);
    echo "Secure serialize:no-throw\n";
} catch (Throwable $e1) {
    echo 'Secure serialize ', get_class($e1), ':', $e1->getMessage(), "\n";
}

try {
    unserialize('O:20:"Random\Engine\Secure":0:{}');
    echo "Secure unserialize:no-throw\n";
} catch (Throwable $e2) {
    echo 'Secure unserialize ', get_class($e2), ':', $e2->getMessage(), "\n";
}

$mt = new Random\Engine\Mt19937(1);
try {
    $payload = serialize($mt);
    echo 'Mt19937 serialize:ok len=', strlen($payload), "\n";
} catch (Throwable $e3) {
    echo 'Mt19937 serialize ', get_class($e3), ':', $e3->getMessage(), "\n";
}
