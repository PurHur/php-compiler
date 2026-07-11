<?php

declare(strict_types=1);

$missing = '/no/such/phpc-highlight-missing-return-false.php';
$r = highlight_file($missing, true);
if (false !== $r || 'boolean' !== gettype($r)) {
    echo 'highlight_file: fail type='.gettype($r).' value='.var_export($r, true)."\n";
    exit(1);
}
$s = show_source($missing, true);
if (false !== $s || 'boolean' !== gettype($s)) {
    echo 'show_source: fail type='.gettype($s).' value='.var_export($s, true)."\n";
    exit(1);
}
echo "ok\n";
