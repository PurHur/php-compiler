<?php

/**
 * Repro #22243 — XSLTProcessor::registerPHPFunctionNS() API + validation.
 *
 * Requires PHP_COMPILER_PROFILE=8.4 in the process environment (class registration
 * reads the gate before this script body runs).
 */
$p = new XSLTProcessor();
echo 'has=', method_exists($p, 'registerPHPFunctionNS') ? '1' : '0', "\n";

try {
    $p->registerPHPFunctionNS('http://php.net/xsl', 'strlen', 'strlen');
    echo "reserved_ok\n";
} catch (ValueError $e) {
    echo 'reserved:', (str_contains($e->getMessage(), 'reserved by PHP') ? '1' : '0'), "\n";
}

try {
    $p->registerPHPFunctionNS('urn:foo', 'x:a', 'strlen');
    echo "ncname_ok\n";
} catch (ValueError $e) {
    echo 'ncname:', (str_contains($e->getMessage(), 'valid callback name') ? '1' : '0'), "\n";
}

try {
    $p->registerPHPFunctionNS('urn:foo', 'strlen', 123);
    echo "callable_ok\n";
} catch (TypeError $e) {
    echo 'callable:', (str_contains($e->getMessage(), 'callable') ? '1' : '0'), "\n";
}

$p->registerPHPFunctionNS('urn:foo', 'strlen', 'strlen');
echo "reg_ok\n";
