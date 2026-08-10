<?php

declare(strict_types=1);

try {
    DateTime::createFromFormat(null, 'x');
    echo "fail:DateTime\n";
} catch (TypeError $e) {
    echo 'ok:DateTime:', $e->getMessage(), "\n";
}

try {
    DateTimeImmutable::createFromFormat(null, 'x');
    echo "fail:DateTimeImmutable\n";
} catch (TypeError $e) {
    echo 'ok:DateTimeImmutable:', $e->getMessage(), "\n";
}
