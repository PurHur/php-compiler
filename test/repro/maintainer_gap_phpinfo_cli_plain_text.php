<?php

declare(strict_types=1);

ob_start();
phpinfo(INFO_GENERAL);
$out = ob_get_clean();

if (str_starts_with($out, '<!DOCTYPE')) {
    fwrite(STDERR, "FAIL: phpinfo(INFO_GENERAL) emitted HTML in CLI SAPI\n");
    exit(1);
}
if (!str_contains($out, 'PHP Version =>')) {
    fwrite(STDERR, "FAIL: phpinfo(INFO_GENERAL) missing plain-text PHP Version row\n");
    exit(1);
}
if (!str_contains($out, 'Server API => Command Line Interface')) {
    fwrite(STDERR, "FAIL: phpinfo(INFO_GENERAL) missing CLI Server API label\n");
    exit(1);
}

$len = \strlen($out);
if ($len < 700) {
    fwrite(STDERR, "FAIL: phpinfo(INFO_GENERAL) output too short ({$len} bytes)\n");
    exit(1);
}

echo "ok len={$len}\n";
