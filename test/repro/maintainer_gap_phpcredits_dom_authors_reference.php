<?php

declare(strict_types=1);

ob_start();
phpcredits(CREDITS_ALL);
$out = ob_get_clean();

if (str_contains($out, 'Nora Dossche')) {
    fwrite(STDERR, "FAIL: CREDITS_ALL must not list Nora Dossche on 8.2 reference profile\n");
    exit(1);
}

if (!str_contains($out, 'DOM => Christian Stocker, Rob Richards, Marcus Boerger')) {
    fwrite(STDERR, "FAIL: CREDITS_ALL DOM row missing expected 8.2 authors\n");
    exit(1);
}

if (str_contains($out, 'uri =>')) {
    fwrite(STDERR, "FAIL: CREDITS_ALL must not list ext/uri row on 8.2 reference profile\n");
    exit(1);
}

echo "ok len=" . \strlen($out) . "\n";
