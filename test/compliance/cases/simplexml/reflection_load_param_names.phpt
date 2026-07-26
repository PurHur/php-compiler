--TEST--
simplexml_load_* Reflection/named params match php-src stubs (#23455)
--FILE--
<?php
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
--EXPECT--
string:data,class_name,options,namespace_or_prefix,is_prefix
file:filename,class_name,options,namespace_or_prefix,is_prefix
data-named:a
ns-ok
legacy-ns-reject
ok
