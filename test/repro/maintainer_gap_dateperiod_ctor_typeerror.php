<?php

declare(strict_types=1);

try {
    new DatePeriod('2020-01-01', 'P1D', '2020-01-03');
} catch (TypeError $e) {
    $msg = $e->getMessage();
    if (!str_contains($msg, 'DatePeriod::__construct() accepts')) {
        fwrite(STDERR, "FAIL: unexpected TypeError message: {$msg}\n");
        exit(1);
    }
    echo "DatePeriod TypeError ok\n";
    exit(0);
}

fwrite(STDERR, "FAIL: expected TypeError was not thrown\n");
exit(1);
