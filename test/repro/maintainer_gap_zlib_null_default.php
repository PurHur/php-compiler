<?php
// #19004 — gzcompress/gzuncompress/gzinflate(null) must TypeError on default profile (ext/zlib/zlib.c).
// Run with: env -u PHP_COMPILER_PROFILE php bin/vm.php test/repro/maintainer_gap_zlib_null_default.php

foreach (['gzcompress', 'gzuncompress', 'gzinflate'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
