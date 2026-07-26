<?php
/**
 * #23455 — simplexml_load_string/file Reflection + named args match php-src stubs
 * (ext/simplexml/simplexml.stub.php): namespace_or_prefix not ns; data/filename accepted.
 */
$r = new ReflectionFunction('simplexml_load_string');
echo 'string:', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
$r2 = new ReflectionFunction('simplexml_load_file');
echo 'file:', implode(',', array_map(static fn ($p) => $p->getName(), $r2->getParameters())), "\n";
$x = simplexml_load_string(data: '<r><a/></r>');
echo 'data-named:', $x->a->getName(), "\n";
$x = simplexml_load_string(data: '<r xmlns:p="u"><p:a/></r>', namespace_or_prefix: 'p', is_prefix: true);
echo "ns-ok\n";
try {
    simplexml_load_string(data: '<r/>', ns: 'p', is_prefix: true);
    echo "legacy-ns-ok\n";
} catch (Throwable $e) {
    echo "legacy-ns-reject\n";
}
echo "ok\n";
