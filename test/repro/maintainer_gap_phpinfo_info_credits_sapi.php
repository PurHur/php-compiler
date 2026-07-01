<?php

declare(strict_types=1);

ob_start();
phpinfo(INFO_CREDITS);
$out = ob_get_clean();

foreach (['SAPI Modules', 'Module Authors', 'PHP Authors', 'PHP Quality Assurance Team'] as $section) {
    if (!str_contains($out, $section)) {
        fwrite(STDERR, "FAIL: phpinfo(INFO_CREDITS) missing {$section}\n");
        exit(1);
    }
}

$len = \strlen($out);
$minLen = extension_loaded('curl') ? 6500 : 5500;
if ($len < $minLen) {
    fwrite(STDERR, "FAIL: phpinfo(INFO_CREDITS) output too short ({$len} bytes, expected >={$minLen})\n");
    exit(1);
}

echo "ok len={$len}\n";
