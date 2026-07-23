<?php
/**
 * Repro #22530 — simdjson_decode / simdjson_is_valid MVP (PECL simdjson).
 * Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_22530_simdjson.php
 */
declare(strict_types=1);

if (!function_exists('simdjson_decode')) {
    fwrite(STDERR, "FAIL: simdjson_decode missing (need PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}

$ok = true;
if (!simdjson_is_valid('{"a":1}')) {
    fwrite(STDERR, "FAIL: simdjson_is_valid\n");
    $ok = false;
}
$decoded = simdjson_decode('{"a":1}', true);
if ($decoded !== ['a' => 1]) {
    fwrite(STDERR, 'FAIL: simdjson_decode got '.var_export($decoded, true)."\n");
    $ok = false;
}
echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
