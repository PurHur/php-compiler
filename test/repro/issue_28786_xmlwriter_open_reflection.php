<?php
/** Issue #28786 — xmlwriter_open_memory/open_uri Reflection XMLWriter|false (not resource). */
foreach (['xmlwriter_open_memory', 'xmlwriter_open_uri'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
$w = xmlwriter_open_memory();
echo 'runtime=', get_debug_type($w), "\n";