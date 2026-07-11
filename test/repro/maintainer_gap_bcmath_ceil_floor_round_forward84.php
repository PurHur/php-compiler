<?php

declare(strict_types=1);

$fail = 0;

foreach (['bcceil', 'bcfloor', 'bcround'] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "FAIL: function_exists({$fn}) is false\n");
        ++$fail;
    }
}

if (0 !== $fail) {
    exit(1);
}

echo 'bcceil=', bcceil('1.2'), "\n";
echo 'bcfloor=', bcfloor('1.9'), "\n";
echo 'bcround=', bcround('1.5', 0), "\n";
