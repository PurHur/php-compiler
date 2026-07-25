<?php
// Repro #23193 — basename/uniqid/gettype/array_key_exists Zend stub named parameters
// Avoid `true === array_key_exists(...)` — pre-existing ARG_SEND/SSA bug with
// array_key_exists under Identical (separate from named-param registration).
$ok = 'b' === basename(path: '/a/b.txt', suffix: '.txt')
    && str_starts_with(uniqid(prefix: 'x', more_entropy: true), 'x')
    && 'array' === gettype(value: [])
    && array_key_exists(key: 'a', array: ['a' => 1]);
$rf = new ReflectionFunction('gettype');
$gettypeNames = [];
foreach ($rf->getParameters() as $p) {
    $gettypeNames[] = $p->getName();
}
$rf = new ReflectionFunction('array_key_exists');
$akeNames = [];
foreach ($rf->getParameters() as $p) {
    $akeNames[] = $p->getName();
}
$ok = $ok
    && ['value'] === $gettypeNames
    && ['key', 'array'] === $akeNames;
echo $ok ? "ok\n" : "fail\n";
