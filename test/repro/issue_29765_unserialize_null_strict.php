<?php
// Repro #29765 — unserialize(null) under declare(strict_types=1) must TypeError
declare(strict_types=1);

try {
    var_export(unserialize(null));
    echo "\nuncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
