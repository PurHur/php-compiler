<?php
/**
 * #26236 — libxml_set_streams_context Reflection / named params match
 * php-src stubs (ext/libxml/libxml.stub.php): context; reject streams_context.
 */
$rf = new ReflectionFunction('libxml_set_streams_context');
echo implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$c = stream_context_create();
try {
    libxml_set_streams_context(context: $c);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    libxml_set_streams_context(streams_context: $c);
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo "legacy-reject\n";
}
