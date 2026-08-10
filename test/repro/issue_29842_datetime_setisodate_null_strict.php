<?php

declare(strict_types=1);

try {
    (new DateTime('2020-01-01'))->setISODate(null, 1);
    echo "fail:DateTime\n";
} catch (TypeError $e) {
    echo 'ok:DateTime:', $e->getMessage(), "\n";
}

try {
    (new DateTimeImmutable('2020-01-01'))->setISODate(null, 1);
    echo "fail:DateTimeImmutable\n";
} catch (TypeError $e) {
    echo 'ok:DateTimeImmutable:', $e->getMessage(), "\n";
}

try {
    date_isodate_set(date_create('2020-01-01'), null, 1);
    echo "fail:date_isodate_set\n";
} catch (TypeError $e) {
    echo 'ok:date_isodate_set:', $e->getMessage(), "\n";
}
