<?php

declare(strict_types=1);

$mac = filter_var('00:00:5e:00:53:af', FILTER_VALIDATE_MAC);
if ('00:00:5e:00:53:af' !== $mac) {
    fwrite(STDERR, "expected colon MAC, got ".var_export($mac, true)."\n");
    exit(1);
}

$hyphen = filter_var('FA-F9-DD-B2-5E-0D', FILTER_VALIDATE_MAC);
if ('FA-F9-DD-B2-5E-0D' !== $hyphen) {
    fwrite(STDERR, "expected hyphen MAC, got ".var_export($hyphen, true)."\n");
    exit(1);
}

$bad = filter_var('not-a-mac', FILTER_VALIDATE_MAC);
if (false !== $bad) {
    fwrite(STDERR, "expected false for invalid MAC, got ".var_export($bad, true)."\n");
    exit(1);
}

echo "ok\n";
