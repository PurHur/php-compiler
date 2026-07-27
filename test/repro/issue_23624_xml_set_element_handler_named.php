<?php
/**
 * #23624 — xml_set_element_handler Reflection / named params match php-src stubs
 * (ext/xml/xml.stub.php): parser/start_handler/end_handler; reject legacy shdl/ehdl.
 */
$rf = new ReflectionFunction('xml_set_element_handler');
echo implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$p = xml_parser_create();
try {
    xml_set_element_handler(parser: $p, start_handler: 'strlen', end_handler: 'strlen');
    echo "named_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    xml_set_element_handler(parser: $p, shdl: 'strlen', ehdl: 'strlen');
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo "legacy-reject\n";
}
