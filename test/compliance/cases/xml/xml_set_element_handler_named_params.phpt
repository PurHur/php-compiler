--TEST--
xml_set_element_handler Reflection/named params match php-src stubs (#23624)
--FILE--
<?php
$rf = new ReflectionFunction('xml_set_element_handler');
echo implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$p = xml_parser_create();
var_export(xml_set_element_handler(parser: $p, start_handler: 'strlen', end_handler: 'strlen'));
echo "\n";
try {
    xml_set_element_handler(parser: $p, shdl: 'strlen', ehdl: 'strlen');
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo "legacy-reject\n";
}
echo "ok\n";
--EXPECT--
parser,start_handler,end_handler
true
legacy-reject
ok
