<?php

declare(strict_types=1);

// Compile-only (#18156): openssl_random_pseudo_bytes() length guard lowers for AOT.
try {
    openssl_random_pseudo_bytes(-1, $strong);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
