<?php
// Repro #23226 — ucwords Zend stub named parameters (string/separators)
$names = [];
foreach ((new ReflectionFunction('ucwords'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$one = ucwords(string: 'hello world');
$two = ucwords(string: 'a-b', separators: '-');
$ok = ['string', 'separators'] === $names
    && $one === 'Hello World'
    && $two === 'A-B';
try {
    ucwords(str: 'hello world', delims: '-');
    $legacyRejected = false;
} catch (Error $e) {
    $legacyRejected = str_contains($e->getMessage(), 'Unknown named parameter $str');
}
echo ($ok && $legacyRejected) ? "ok\n" : "fail\n";
