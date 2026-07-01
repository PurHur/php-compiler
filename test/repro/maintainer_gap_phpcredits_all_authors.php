<?php

declare(strict_types=1);

ob_start();
phpcredits(CREDITS_ALL);
$out = ob_get_clean();

if (!str_contains($out, 'PHP Authors')) {
    fwrite(STDERR, "FAIL: CREDITS_ALL missing PHP Authors section\n");
    exit(1);
}

if (!str_contains($out, 'Zend Scripting Language Engine')) {
    fwrite(STDERR, "FAIL: CREDITS_ALL missing Zend Scripting Language Engine row\n");
    exit(1);
}

$len = \strlen($out);
if ($len < 5500) {
    fwrite(STDERR, "FAIL: CREDITS_ALL output too short ({$len} bytes, expected >=5500 with PHP Authors)\n");
    exit(1);
}

echo "ok len={$len}\n";
