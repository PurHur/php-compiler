<?php

/**
 * Issue #24223 — get_declared_functions()/get_declared_variables() phantom registration.
 *
 * php-src exposes get_defined_functions() / get_defined_vars() only.
 * Expected: phantoms absent; Zend names present.
 */

$results = [];
foreach ([
    'get_declared_functions' => false,
    'get_declared_variables' => false,
    'get_defined_functions' => true,
    'get_defined_vars' => true,
] as $f => $want) {
    $exists = function_exists($f);
    $ok = $exists === $want;
    $results[] = "$f=" . ($exists ? 'exists' : 'absent') . ($ok ? '(OK)' : '(BUG)');
}

echo implode("\n", $results) . "\n";
