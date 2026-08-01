--TEST--
libxml_set_streams_context Reflection/named params match php-src stubs (#26236)
--FILE--
<?php
$rf = new ReflectionFunction('libxml_set_streams_context');
echo implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$c = stream_context_create();
libxml_set_streams_context(context: $c);
echo "ok\n";
try {
    libxml_set_streams_context(streams_context: $c);
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo "legacy-reject\n";
}
--EXPECT--
context
ok
legacy-reject
