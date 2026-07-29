<?php
/** Repro for #24584 — stream_context_get_options Reflection + Zend named stream_or_context. */
$r = new ReflectionFunction('stream_context_get_options');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'names=', implode(',', $names), ' arity=', $r->getNumberOfParameters(), "\n";
$ctx = stream_context_create(['http' => ['method' => 'GET']]);
try {
    $opts = stream_context_get_options(stream_or_context: $ctx);
    echo 'named=', isset($opts['http']['method']) ? $opts['http']['method'] : 'missing', "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    stream_context_get_options(context: $ctx);
    echo "context_named=ok\n";
} catch (Throwable $e) {
    echo 'context_named=', get_class($e), ':', $e->getMessage(), "\n";
}
$opts2 = stream_context_get_options($ctx);
echo 'pos=', isset($opts2['http']['method']) ? $opts2['http']['method'] : 'missing', "\n";
