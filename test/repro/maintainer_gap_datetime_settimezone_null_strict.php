<?php

declare(strict_types=1);

$d = new DateTime('@0');

try {
    $d->setTimezone(null);
    echo "fail:DateTime::setTimezone\n";
} catch (TypeError $e) {
    echo 'ok:DateTime::setTimezone:', $e->getMessage(), "\n";
}

try {
    (new DateTimeImmutable('@0'))->setTimezone(null);
    echo "fail:DateTimeImmutable::setTimezone\n";
} catch (TypeError $e) {
    echo 'ok:DateTimeImmutable::setTimezone:', $e->getMessage(), "\n";
}

try {
    date_timezone_set($d, null);
    echo "fail:date_timezone_set\n";
} catch (TypeError $e) {
    echo 'ok:date_timezone_set:', $e->getMessage(), "\n";
}
