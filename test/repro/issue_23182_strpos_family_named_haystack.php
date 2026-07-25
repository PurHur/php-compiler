<?php
// Repro #23182 — strpos/stripos/strrpos/strstr haystack: named parameters
$ok = 2 === strpos(haystack: 'abcdef', needle: 'cd')
    && 2 === stripos(haystack: 'ABCDEF', needle: 'cd')
    && 6 === strrpos(haystack: 'ab cd cd', needle: 'cd')
    && 'cdef' === strstr(haystack: 'abcdef', needle: 'cd')
    && 'ab' === strstr(haystack: 'abcdef', needle: 'cd', before_needle: true);
$rf = new ReflectionFunction('strstr');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$ok = $ok && ['haystack', 'needle', 'before_needle'] === $names;
echo $ok ? "ok\n" : "fail\n";
