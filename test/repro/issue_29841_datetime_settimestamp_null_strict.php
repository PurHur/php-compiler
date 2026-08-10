<?php

declare(strict_types=1);

try {
    (new DateTime('2020-01-01'))->setTimestamp(null);
    echo "fail:DateTime\n";
} catch (TypeError $e) {
    echo 'ok:DateTime:', $e->getMessage(), "\n";
}

try {
    (new DateTimeImmutable('2020-01-01'))->setTimestamp(null);
    echo "fail:DateTimeImmutable\n";
} catch (TypeError $e) {
    echo 'ok:DateTimeImmutable:', $e->getMessage(), "\n";
}

try {
    date_timestamp_set(date_create('@1'), null);
    echo "fail:date_timestamp_set\n";
} catch (TypeError $e) {
    echo 'ok:date_timestamp_set:', $e->getMessage(), "\n";
}
