<?php
// #23406 — fsync/fdatasync Reflection stream + named stream: + bool return.
foreach (['fsync', 'fdatasync'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' arity=', $r->getNumberOfParameters(),
        ' required=', $r->getNumberOfRequiredParameters(),
        ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(), '|', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
    }
    $tmp = tmpfile();
    echo '  named=', var_export($f(stream: $tmp), true), "\n";
    fclose($tmp);
}
