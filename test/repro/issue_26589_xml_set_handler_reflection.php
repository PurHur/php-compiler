<?php
/**
 * #26589 — xml_set_*_handler Reflection matches php-src ext/xml/xml.stub.php
 * (XMLParser $parser, untyped $handler / start_handler / end_handler, return true).
 */
$funcs = [
    'xml_set_character_data_handler',
    'xml_set_default_handler',
    'xml_set_element_handler',
    'xml_set_end_namespace_decl_handler',
    'xml_set_external_entity_ref_handler',
    'xml_set_notation_decl_handler',
    'xml_set_processing_instruction_handler',
    'xml_set_start_namespace_decl_handler',
    'xml_set_unparsed_entity_decl_handler',
];
foreach ($funcs as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $ps[] = $t . '$' . $p->getName();
    }
    echo $fn, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
$p = xml_parser_create();
try {
    xml_set_character_data_handler(parser: $p, handler: function ($a, $b) {});
    echo "named_handler=ok\n";
} catch (Throwable $e) {
    echo 'named_handler=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    xml_set_character_data_handler(parser: $p, hdl: function ($a, $b) {});
    echo "named_hdl=ok\n";
} catch (Throwable $e) {
    echo "named_hdl=reject\n";
}
echo 'positional=', xml_set_character_data_handler($p, function ($a, $b) {}) ? 'true' : 'false', "\n";
?>
