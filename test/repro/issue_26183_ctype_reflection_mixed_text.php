<?php
// Repro #26183 — ctype_* Reflection mixed $text after #23192 named params

foreach (['ctype_alnum', 'ctype_digit', 'ctype_alpha'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        echo "$fn \$".$p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
    }
    echo "$fn ret=", $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}

try {
    var_export(ctype_alnum(text: 'abc'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
