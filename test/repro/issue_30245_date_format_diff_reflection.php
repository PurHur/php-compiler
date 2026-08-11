<?php
foreach (['date_format', 'date_diff'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(), ' ty=', $p->hasType() ? (string) $p->getType() : '-', "\n";
    }
}
