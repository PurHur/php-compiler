<?php

declare(strict_types=1);

/**
 * #23949 AOT probe — 2-arg parse_str keeps default `&` delimiter (no phantom separator).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/x test/repro/issue_23949_parse_str_separator_phantom_aot.php && /tmp/x
 */

$out = [];
parse_str('a=1;b=2', $out);
if (!isset($out['a']) || '1;b=2' !== $out['a']) {
    echo "FAIL a=", isset($out['a']) ? $out['a'] : 'missing', "\n";
    exit(1);
}
echo "ok\n";
