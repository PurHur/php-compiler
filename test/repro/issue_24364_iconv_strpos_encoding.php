<?php
// Repro #24364 — iconv_strpos/iconv_strrpos Zend stub encoding (not charset)
$strposNames = [];
foreach ((new ReflectionFunction('iconv_strpos'))->getParameters() as $p) {
    $strposNames[] = $p->getName();
}
$strrposNames = [];
foreach ((new ReflectionFunction('iconv_strrpos'))->getParameters() as $p) {
    $strrposNames[] = $p->getName();
}

$namedStrpos = iconv_strpos(haystack: 'abc', needle: 'b', offset: 0, encoding: 'UTF-8');
$namedStrrpos = iconv_strrpos(haystack: 'abcb', needle: 'b', encoding: 'UTF-8');
$positionalStrpos = iconv_strpos('abc', 'b', 0, 'UTF-8');
$positionalStrrpos = iconv_strrpos('abcb', 'b', 'UTF-8');

$charsetRejected = false;
try {
    iconv_strpos(haystack: 'abc', needle: 'b', offset: 0, charset: 'UTF-8');
} catch (Error $e) {
    $charsetRejected = str_contains($e->getMessage(), 'charset');
}

$ok = ['haystack', 'needle', 'offset', 'encoding'] === $strposNames
    && ['haystack', 'needle', 'encoding'] === $strrposNames
    && 1 === $namedStrpos
    && 3 === $namedStrrpos
    && $namedStrpos === $positionalStrpos
    && $namedStrrpos === $positionalStrrpos
    && $charsetRejected;
echo $ok ? "ok\n" : "fail\n";
