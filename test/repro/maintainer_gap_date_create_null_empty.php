<?php

declare(strict_types=1);

try {
    date_create(null);
    fwrite(STDERR, "date_create(null): expected TypeError\n");
    exit(1);
} catch (TypeError) {
    echo "date_create(null): ok\n";
}

try {
    new DateTime(null);
    fwrite(STDERR, "DateTime(null): expected TypeError\n");
    exit(1);
} catch (TypeError) {
    echo "DateTime(null): ok\n";
}

foreach (['date_create' => static fn () => date_create(''), 'DateTime' => static fn () => new DateTime('')] as $label => $factory) {
    $dt = $factory();
    if (!($dt instanceof DateTime)) {
        fwrite(STDERR, "$label(''): expected DateTime instance\n");
        exit(1);
    }
    echo "$label(''): ok\n";
}
