<?php

declare(strict_types=1);

ob_start();
phpinfo(INFO_GENERAL);
$out = ob_get_clean();
if (!str_contains($out, 'Build Date')) {
    echo "fail: Build Date row missing\n";
    exit(1);
}
if (preg_match('/Build Date\s*<\/td><td class="v">([^<]+)/', $out, $m) && '' !== trim($m[1])) {
    echo 'fail: Build Date value non-empty: ', trim($m[1]), "\n";
    exit(1);
}
echo "ok\n";
