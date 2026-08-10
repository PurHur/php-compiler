<?php

declare(strict_types=1);

try {
    var_export((new DateTime('2020-01-01'))->modify(null));
    echo "\nfail: expected TypeError\n";
} catch (TypeError $e) {
    echo 'ok:', $e->getMessage(), "\n";
}

try {
    var_export((new DateTimeImmutable('2020-01-01'))->modify(null));
    echo "\nfail:immutable: expected TypeError\n";
} catch (TypeError $e) {
    echo 'ok:immutable:', $e->getMessage(), "\n";
}
