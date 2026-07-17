<?php

/**
 * Repro #20119 — DOMXPath::registerPhpFunctionNS() + namespaced XPath predicate.
 *
 * Requires PHP_COMPILER_PROFILE=8.4 in the process environment (class registration
 * reads the gate before this script body runs).
 */
$doc = new DOMDocument();
$doc->loadHTML('<a href="https://PHP.net">hello</a>');
$xp = new DOMXPath($doc);
echo 'has=', method_exists($xp, 'registerPhpFunctionNS') ? '1' : '0', "\n";

try {
    $xp->registerPhpFunctionNS('http://php.net/xpath', 'strtolower', 'strtolower');
    echo "reserved_ok\n";
} catch (ValueError $e) {
    echo "reserved:", (str_contains($e->getMessage(), 'reserved by PHP') ? '1' : '0'), "\n";
}

try {
    $xp->registerPhpFunctionNS('urn:foo', '$$$', 'strtolower');
    echo "ncname_ok\n";
} catch (ValueError $e) {
    echo "ncname:", (str_contains($e->getMessage(), 'valid callback name') ? '1' : '0'), "\n";
}

$xp->registerNamespace('foo', 'urn:foo');
$xp->registerPhpFunctionNS('urn:foo', 'strtolower', 'strtolower');
$list = $xp->query('//a[foo:strtolower(string(@href)) = "https://php.net"]');
echo 'len=', $list->length, "\n";
echo "ok\n";
