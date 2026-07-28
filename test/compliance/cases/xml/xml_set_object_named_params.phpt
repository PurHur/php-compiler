--TEST--
xml_set_object Reflection/named params match php-src stubs (#23946)
--FILE--
<?php
$rf = new ReflectionFunction('xml_set_object');
echo implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$p = xml_parser_create();
$o = new stdClass();
var_export(xml_set_object(parser: $p, object: $o));
echo "\n";
try {
    xml_set_object(parser: $p, obj: $o);
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo "legacy-reject\n";
}
echo "ok\n";
--EXPECT--
parser,object
true
legacy-reject
ok
