<?php

declare(strict_types=1);

if (function_exists('json_validate')) {
    fwrite(STDERR, "FAIL: json_validate advertised on reference profile\n");
    exit(1);
}

echo "ok\n";
