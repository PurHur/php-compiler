<?php

declare(strict_types=1);

try {
    var_export(strstr('abc', null));
    echo "\nfail: expected TypeError\n";
} catch (TypeError $e) {
    echo 'ok:', $e->getMessage(), "\n";
}
