<?php
// Repro #23623 — mb_detect_encoding Zend stub named parameters
$names = [];
foreach ((new ReflectionFunction('mb_detect_encoding'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$named = mb_detect_encoding(string: 'abc', encodings: ['UTF-8']);
$positional = mb_detect_encoding('abc', ['UTF-8']);
$legacyRejected = false;
try {
    mb_detect_encoding(str: 'abc');
} catch (Error $e) {
    $legacyRejected = str_contains($e->getMessage(), 'str');
}
$ok = ['string', 'encodings', 'strict'] === $names
    && 'UTF-8' === $named
    && $named === $positional
    && $legacyRejected;
echo $ok ? "ok\n" : "fail\n";
