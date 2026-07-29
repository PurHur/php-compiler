<?php
/** Repro for #24550 — phpinfo Reflection + Zend named `flags` (ext/standard/info.stub.php). */
$r = new ReflectionFunction('phpinfo');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'names=', implode(',', $names), "\n";
try {
    ob_start();
    $ok = phpinfo(flags: INFO_GENERAL);
    $out = ob_get_clean();
    echo 'flags=', ($ok && strlen($out) > 10) ? 'ok' : 'bad', "\n";
} catch (Throwable $e) {
    echo 'flags=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    ob_start();
    phpinfo(what: INFO_GENERAL);
    ob_end_clean();
    echo "what=accepted\n";
} catch (Throwable $e) {
    echo 'what=', $e->getMessage(), "\n";
}
