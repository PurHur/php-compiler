<?php

declare(strict_types=1);

/**
 * Issue #29083 — stream_context_set_options advertised on PROFILE=8.3 (php-src since 8.3.0).
 *
 * Run with: PHP_COMPILER_PROFILE=8.3 php bin/vm.php test/repro/issue_29083_stream_context_set_options_profile83.php
 */
echo 'exists=', function_exists('stream_context_set_options') ? '1' : '0', "\n";
$ctx = stream_context_create();
$ok = stream_context_set_options($ctx, ['http' => ['method' => 'GET', 'timeout' => 1]]);
echo 'set=', $ok ? '1' : '0', "\n";
$opts = stream_context_get_options($ctx);
echo 'method=', $opts['http']['method'] ?? '?', "\n";
echo 'timeout=', (string) ($opts['http']['timeout'] ?? '?'), "\n";
