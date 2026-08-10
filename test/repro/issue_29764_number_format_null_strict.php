<?php

declare(strict_types=1);

try {
    var_export(number_format(1.5, null));
    echo "\nfail: expected TypeError\n";
} catch (TypeError $e) {
    echo 'ok:', $e->getMessage(), "\n";
}
