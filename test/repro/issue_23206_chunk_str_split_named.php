<?php
// Repro #23206 — chunk_split/str_split Zend stub named parameters
$chunkNames = [];
foreach ((new ReflectionFunction('chunk_split'))->getParameters() as $p) {
    $chunkNames[] = $p->getName();
}
$splitNames = [];
foreach ((new ReflectionFunction('str_split'))->getParameters() as $p) {
    $splitNames[] = $p->getName();
}
$chunkNamed = chunk_split(string: 'abcd', length: 2, separator: '|');
$chunkPositional = chunk_split('abcd', 2, '|');
$splitNamed = str_split(string: 'abcd', length: 2);
$splitPositional = str_split('abcd', 2);
$ok = ['string', 'length', 'separator'] === $chunkNames
    && ['string', 'length'] === $splitNames
    && 'ab|cd|' === $chunkNamed
    && $chunkNamed === $chunkPositional
    && ['ab', 'cd'] === $splitNamed
    && $splitNamed === $splitPositional;
echo $ok ? "ok\n" : "fail\n";
