<?php
// Repro #28583 — mb_strpos family Reflection must be int|false + ?string $encoding
foreach (['mb_strpos', 'mb_strrpos', 'mb_stripos', 'mb_strripos'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
    foreach ($r->getParameters() as $p) {
        if ($p->getName() === 'encoding') {
            echo ' encoding=', $p->hasType() ? (string) $p->getType() : 'untyped';
        }
    }
    echo "\n";
}
var_export(mb_strpos('abc', 'z'));
echo "\n";
