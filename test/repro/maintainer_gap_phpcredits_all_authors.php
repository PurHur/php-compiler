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
if (!str_contains($out, 'Websites and Infrastructure')) {
    fwrite(STDERR, "FAIL: CREDITS_ALL missing Websites and Infrastructure section\n");
    exit(1);
}
if (!str_contains($out, 'PHP Documentation') || !str_contains($out, 'Peter Cowburn')) {
    fwrite(STDERR, "FAIL: CREDITS_ALL missing expanded PHP Documentation section\n");
    exit(1);
}
$minLen = extension_loaded('curl') ? 6500 : 4000;
if ($len < $minLen) {
    fwrite(STDERR, "FAIL: CREDITS_ALL output too short ({$len} bytes, expected >={$minLen})\n");
    exit(1);
}

echo "ok len={$len}\n";
