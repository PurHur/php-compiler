<?php
// Repro #23805 — mb_str_pad / mb_lcfirst / mb_ucfirst Zend stub named params (PROFILE=8.4)
$padNames = [];
foreach ((new ReflectionFunction('mb_str_pad'))->getParameters() as $p) {
    $padNames[] = $p->getName();
}
$lcNames = [];
foreach ((new ReflectionFunction('mb_lcfirst'))->getParameters() as $p) {
    $lcNames[] = $p->getName();
}
$ucNames = [];
foreach ((new ReflectionFunction('mb_ucfirst'))->getParameters() as $p) {
    $ucNames[] = $p->getName();
}
$pad = mb_str_pad(string: 'x', length: 5);
$lc = mb_lcfirst(string: 'ABC');
$uc = mb_ucfirst(string: 'abc');
$positionalPad = mb_str_pad('x', 5);
$positionalLc = mb_lcfirst('ABC');
$positionalUc = mb_ucfirst('abc');
$ok = ['string', 'length', 'pad_string', 'pad_type', 'encoding'] === $padNames
    && ['string', 'encoding'] === $lcNames
    && ['string', 'encoding'] === $ucNames
    && 'x    ' === $pad
    && $pad === $positionalPad
    && 'aBC' === $lc
    && $lc === $positionalLc
    && 'Abc' === $uc
    && $uc === $positionalUc;
echo $ok ? "ok\n" : "fail\n";
