<?php
// Repro #23240 — chr codepoint: named parameter (Zend stub name)
$rf = new ReflectionFunction('chr');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$named = chr(codepoint: 65);
$positional = chr(65);
$asciiRejected = false;
try {
    chr(ascii: 65);
} catch (Error $e) {
    $asciiRejected = str_contains($e->getMessage(), 'Unknown named parameter $ascii');
}
$ok = ['codepoint'] === $names
    && 'A' === $named
    && $named === $positional
    && $asciiRejected;
echo $ok ? "ok\n" : "fail\n";
