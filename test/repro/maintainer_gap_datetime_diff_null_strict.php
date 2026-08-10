<?php

declare(strict_types=1);

try {
    (new DateTime('@0'))->diff(null);
    echo "fail:DateTime::diff\n";
} catch (TypeError $e) {
    echo 'ok:DateTime::diff:', $e->getMessage(), "\n";
}

try {
    (new DateTimeImmutable('@0'))->diff(null);
    echo "fail:DateTimeImmutable::diff\n";
} catch (TypeError $e) {
    echo 'ok:DateTimeImmutable::diff:', $e->getMessage(), "\n";
}
