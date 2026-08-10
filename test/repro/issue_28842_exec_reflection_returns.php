<?php
foreach (['exec', 'system', 'passthru', 'shell_exec'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
}
