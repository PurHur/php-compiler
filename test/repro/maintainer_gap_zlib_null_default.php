<?php

declare(strict_types=1);

// Issue #19023 — gzcompress/gzuncompress/gzinflate(null) coerce to '' on default profile (ext/zlib/zlib.c).
// Run with: env -u PHP_COMPILER_PROFILE php bin/vm.php test/repro/maintainer_gap_zlib_null_default.php

$c = gzcompress(null);
if (8 !== strlen($c)) {
    fwrite(STDERR, 'fail: gzcompress(null) len='.strlen($c)."\n");
    exit(1);
}

if (false !== @gzuncompress(null)) {
    fwrite(STDERR, "fail: gzuncompress(null) should be false\n");
    exit(1);
}

if (false !== @gzinflate(null)) {
    fwrite(STDERR, "fail: gzinflate(null) should be false\n");
    exit(1);
}

echo "ok\n";
