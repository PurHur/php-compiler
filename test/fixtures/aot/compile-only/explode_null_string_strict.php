<?php
// Compile-only (#18600): explode() must lower null $string TypeError guard for AOT.
declare(strict_types=1);

try {
    explode(',', null);
    echo "NO_THROW\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
