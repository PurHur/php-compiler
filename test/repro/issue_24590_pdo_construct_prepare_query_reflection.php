<?php
// #24590 — PDO::__construct/prepare/query Reflection names/arity match Zend stubs.
foreach (['__construct', 'prepare', 'query'] as $m) {
    $r = new ReflectionMethod('PDO', $m);
    $ns = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '(none)';
        $ns[] = $p->getName()
            .($p->isOptional() ? '?' : '')
            .($p->isVariadic() ? '...' : '')
            .':'.$t;
    }
    echo $m, ' req=', $r->getNumberOfRequiredParameters(),
        ' total=', $r->getNumberOfParameters(),
        ' [', implode(', ', $ns), "]\n";
}
