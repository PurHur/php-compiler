<?php

$result = timezone_open(null);
if (false !== $result) {
    fwrite(STDERR, 'timezone_open(null): expected false, got '.gettype($result)."\n");
    exit(1);
}

$err = error_get_last();
if (null === $err || !str_contains($err['message'], 'Unknown or bad timezone ()')) {
    fwrite(STDERR, 'timezone_open(null): expected Warning for empty timezone'."\n");
    exit(1);
}

echo "ok\n";
