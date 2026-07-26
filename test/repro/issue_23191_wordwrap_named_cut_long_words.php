<?php
// Repro #23191 — wordwrap cut_long_words: named parameter (Zend stub name)
$rf = new ReflectionFunction('wordwrap');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$named = wordwrap(string: 'a b c', width: 2, break: "\n", cut_long_words: true);
$positional = wordwrap('a b c', 2, "\n", true);
$cutRejected = false;
try {
    wordwrap(string: 'a b c', width: 2, break: "\n", cut: true);
} catch (Error $e) {
    $cutRejected = str_contains($e->getMessage(), 'Unknown named parameter $cut');
}
$valueErrorOk = false;
try {
    wordwrap('abc', 0, "\n", true);
} catch (ValueError $e) {
    $valueErrorOk = str_contains($e->getMessage(), '($cut_long_words)');
}
$ok = ['string', 'width', 'break', 'cut_long_words'] === $names
    && "a\nb\nc" === $named
    && $named === $positional
    && $cutRejected
    && $valueErrorOk;
echo $ok ? "ok\n" : "fail\n";
