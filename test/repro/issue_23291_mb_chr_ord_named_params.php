<?php
// Repro #23291 — mb_chr/mb_ord Zend stub named parameters
$chrNames = [];
foreach ((new ReflectionFunction('mb_chr'))->getParameters() as $p) {
    $chrNames[] = $p->getName();
}
$ordNames = [];
foreach ((new ReflectionFunction('mb_ord'))->getParameters() as $p) {
    $ordNames[] = $p->getName();
}
$namedChr = mb_chr(codepoint: 0x41);
$namedOrd = mb_ord(string: 'A');
$positionalChr = mb_chr(0x41);
$positionalOrd = mb_ord('A');
$ok = ['codepoint', 'encoding'] === $chrNames
    && ['string', 'encoding'] === $ordNames
    && 'A' === $namedChr
    && 65 === $namedOrd
    && $namedChr === $positionalChr
    && $namedOrd === $positionalOrd;
echo $ok ? "ok\n" : "fail\n";
