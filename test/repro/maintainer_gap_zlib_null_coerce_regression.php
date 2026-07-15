<?php

declare(strict_types=1);

/**
 * Issue #19023 — gzcompress/gzuncompress/gzinflate(null) php-src null→'' coercion (re-#19004).
 *
 * Run with: env -u PHP_COMPILER_PROFILE php bin/vm.php test/repro/maintainer_gap_zlib_null_coerce_regression.php
 */
require __DIR__.'/maintainer_gap_zlib_null_default.php';
