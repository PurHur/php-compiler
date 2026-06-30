<?php

declare(strict_types=1);

if (!method_exists(DateTime::class, 'getOffset')) {
    fwrite(STDERR, "missing_method\n");
    exit(1);
}

$dt = new DateTime('2020-06-21 12:00:00', new DateTimeZone('Europe/London'));
$offset = $dt->getOffset();
echo 'offset='.$offset."\n";

if (3600 !== $offset) {
    fwrite(STDERR, "bad_offset={$offset}\n");
    exit(1);
}
