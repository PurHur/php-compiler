<?php

declare(strict_types=1);

/**
 * Issue #10032 — json_decode() flags: named parameter must bind to arg #4, not #2.
 *
 * php-src: ext/json/json.stub.php — json, associative, depth, flags
 */

var_export(json_decode('1', flags: JSON_BIGINT_AS_STRING));
echo PHP_EOL;
