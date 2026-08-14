<?php
$d = new DateTime('2020-01-01');
echo 'DateTime::setTimestamp+1: ';
try {
    $d->setTimestamp(0, 1);
    echo "ACCEPTED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$i = new DateTimeImmutable('2020-01-01');
echo 'Immutable::setTimestamp+1: ';
try {
    $i->setTimestamp(0, 1);
    echo "ACCEPTED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'DateTime::getLastErrors+1: ';
try {
    DateTime::getLastErrors(1);
    echo "ACCEPTED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'Immutable::getLastErrors+1: ';
try {
    DateTimeImmutable::getLastErrors(1);
    echo "ACCEPTED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
