<?php

declare(strict_types=1);

$d = date_create(null);
if (!($d instanceof DateTime)) {
    fwrite(STDERR, "date_create(null): expected DateTime instance\n");
    exit(1);
}
echo "date_create(null): ok\n";

$dt = new DateTime(null);
if (!($dt instanceof DateTime)) {
    fwrite(STDERR, "DateTime(null): expected DateTime instance\n");
    exit(1);
}
echo "DateTime(null): ok\n";

foreach (['date_create' => static fn () => date_create(''), 'DateTime' => static fn () => new DateTime('')] as $label => $factory) {
    $result = $factory();
    if (!($result instanceof DateTime)) {
        fwrite(STDERR, "$label(''): expected DateTime instance\n");
        exit(1);
    }
    echo "$label(''): ok\n";
}
