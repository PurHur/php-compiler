<?php
// Repro #26056 — PHP 8.4 exit/die Reflection: string|int $status = 0 : never
foreach (['exit', 'die'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[0];
    echo $fn,
        ' status=', $p->hasType() ? (string)$p->getType() : 'none',
        ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'none',
        ' ret=', $r->hasReturnType() ? (string)$r->getReturnType() : 'none',
        "\n";
}
