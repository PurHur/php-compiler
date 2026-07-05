<?php
declare(strict_types=1);

if (!function_exists('nl_langinfo')) {
    fwrite(STDERR, "MISSING: nl_langinfo\n");
    exit(1);
}

setlocale(LC_TIME, 'C');
$codeset = nl_langinfo(CODESET);
if ('UTF-8' !== $codeset) {
    fwrite(STDERR, "fail: CODESET expected UTF-8, got {$codeset}\n");
    exit(1);
}

echo "ok: {$codeset}\n";
