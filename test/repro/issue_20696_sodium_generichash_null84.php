<?php
/** Repro for #20696 — sodium_crypto_generichash(null) TypeError under PROFILE=8.4. */
try {
    $r = sodium_crypto_generichash(null);
    echo 'message coerced:', bin2hex($r), "\n";
} catch (Throwable $e) {
    echo 'message=', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    $r = sodium_crypto_generichash('p', null);
    echo 'key coerced:', bin2hex($r), "\n";
} catch (Throwable $e) {
    echo 'key=', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
echo 'ok:', bin2hex(sodium_crypto_generichash('')), "\n";
