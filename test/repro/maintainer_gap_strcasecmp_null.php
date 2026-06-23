<?php

declare(strict_types=1);

foreach ([['a', null], [null, 'a']] as [$a, $b]) {
    try {
        strcasecmp($a, $b);
        fwrite(STDERR, "expected TypeError on null operand\n");
        exit(1);
    } catch (TypeError) {
        echo "ok\n";
    }
}
