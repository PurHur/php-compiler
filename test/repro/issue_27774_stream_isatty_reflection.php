<?php
/**
 * #27774 — stream_isatty Reflection return bool.
 */
$r = new ReflectionFunction('stream_isatty');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '(none)', "\n";
}
$fh = fopen('php://memory', 'r');
echo 'mem=', var_export(stream_isatty(stream: $fh), true), "\n";
fclose($fh);
