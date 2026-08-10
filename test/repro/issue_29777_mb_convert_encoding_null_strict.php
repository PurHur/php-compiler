<?php

declare(strict_types=1);

try {
    var_export(mb_convert_encoding(null, 'UTF-8'));
    echo "\nfail: expected TypeError\n";
} catch (TypeError $e) {
    echo 'ok:', $e->getMessage(), "\n";
}
