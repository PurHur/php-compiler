<?php
// Compile-only (#18633): htmlspecialchars_decode() must lower null TypeError guard for AOT.
declare(strict_types=1);

try {
    htmlspecialchars_decode(null);
    echo "NO_THROW\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
