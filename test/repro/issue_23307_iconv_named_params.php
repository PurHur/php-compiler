<?php
// Repro #23307 — iconv/iconv_strlen/iconv_substr Zend stub named parameters
$iconvNames = [];
foreach ((new ReflectionFunction('iconv'))->getParameters() as $p) {
    $iconvNames[] = $p->getName();
}
$strlenNames = [];
foreach ((new ReflectionFunction('iconv_strlen'))->getParameters() as $p) {
    $strlenNames[] = $p->getName();
}
$substrNames = [];
foreach ((new ReflectionFunction('iconv_substr'))->getParameters() as $p) {
    $substrNames[] = $p->getName();
}

$namedIconv = iconv(from_encoding: 'UTF-8', to_encoding: 'UTF-8', string: 'a');
$namedStrlen = iconv_strlen(string: 'ä', encoding: 'UTF-8');
$namedSubstr = iconv_substr(string: 'abcdef', offset: 1, length: 2, encoding: 'UTF-8');
$positionalIconv = iconv('UTF-8', 'UTF-8', 'a');
$positionalStrlen = iconv_strlen('ä', 'UTF-8');
$positionalSubstr = iconv_substr('abcdef', 1, 2, 'UTF-8');

$ok = ['from_encoding', 'to_encoding', 'string'] === $iconvNames
    && ['string', 'encoding'] === $strlenNames
    && ['string', 'offset', 'length', 'encoding'] === $substrNames
    && 'a' === $namedIconv
    && 1 === $namedStrlen
    && 'bc' === $namedSubstr
    && $namedIconv === $positionalIconv
    && $namedStrlen === $positionalStrlen
    && $namedSubstr === $positionalSubstr;
echo $ok ? "ok\n" : "fail\n";
