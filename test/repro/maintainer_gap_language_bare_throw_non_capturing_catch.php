<?php

declare(strict_types=1);

class Inner extends Exception {}

try {
    throw new Inner('inner');
} catch (Inner) {
    echo "caught\n";
}

try {
    try {
        throw new Inner('rethrow');
    } catch (Inner) {
        throw;
    }
} catch (Inner $e) {
    echo $e->getMessage(), "\n";
}
