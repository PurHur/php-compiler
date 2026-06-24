<?php

declare(strict_types=1);

/**
 * Issue #11181 — json_encode() on circular array must return false + JSON_ERROR_RECURSION.
 *
 * php-src: ext/json/php_json.c — JSON_ERROR_RECURSION (6).
 *
 * Verify:
 *   php bin/vm.php test/repro/maintainer_gap_json_encode_circular.php
 *   php bin/jit.php test/repro/maintainer_gap_json_encode_circular.php
 *   php bin/compile.php -l test/repro/maintainer_gap_json_encode_circular.php
 */
$a = [];
$a[] = &$a;
var_export(json_encode($a));
echo "\n";
echo json_last_error(), "\n";
