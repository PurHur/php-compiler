<?php
// Issue #25174 — trigger_error/user_error Reflection types + error_level=E_USER_NOTICE.
foreach (['trigger_error', 'user_error'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        echo $fn, ' ', $p->getName(),
            ' type=', $p->hasType() ? (string) $p->getType() : '-',
            ' opt=', $p->isOptional() ? '1' : '0',
            ' def=', $p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-',
            "\n";
    }
}
