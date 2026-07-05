<?php

declare(strict_types=1);

try {
    new DateInterval('P');
    fwrite(STDERR, "FAIL: expected Exception on malformed DateInterval spec\n");
    exit(1);
} catch (DateMalformedIntervalException $e) {
    fwrite(STDERR, 'FAIL: DateMalformedIntervalException on 8.2 profile: '.$e->getMessage()."\n");
    exit(1);
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
