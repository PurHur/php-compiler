<?php
declare(strict_types=1);

// Repro for #25471 — finfo_* Reflection stubs vs Zend.
foreach (['finfo_open', 'finfo_file', 'finfo_buffer'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '?', ' opt=', $p->isOptional() ? 'Y' : 'N';
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
