<?php
/**
 * #28908 — stream_filter_append/prepend Reflection filter_name/mode/params + no return.
 */
foreach (['stream_filter_append', 'stream_filter_prepend'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $ps[] = $p->getName();
    }
    echo $f, ' [', implode(', ', $ps), '] ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
}
$fp = fopen('php://memory', 'r+');
try {
    stream_filter_append(stream: $fp, filter_name: 'string.toupper');
    echo "named_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
