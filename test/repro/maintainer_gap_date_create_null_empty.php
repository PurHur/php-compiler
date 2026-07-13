<?php

declare(strict_types=1);

putenv('PHP_COMPILER_PROFILE=8.4');

foreach ([
    'date_create(null)' => static fn () => date_create(null),
    'DateTime(null)' => static fn () => new DateTime(null),
] as $label => $factory) {
    try {
        $factory();
        fwrite(STDERR, "$label: expected TypeError\n");
        exit(1);
    } catch (TypeError $e) {
        echo "$label: TypeError\n";
    }
}

foreach (['date_create' => static fn () => date_create(''), 'DateTime' => static fn () => new DateTime('')] as $label => $factory) {
    $result = $factory();
    if (!($result instanceof DateTime)) {
        fwrite(STDERR, "$label(''): expected DateTime instance\n");
        exit(1);
    }
    echo "$label(''): ok\n";
}
