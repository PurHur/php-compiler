<?php
/**
 * #26319 — xml_set_object / xml_parser_create / xml_parse Reflection types
 * match php-src ext/xml/xml.stub.php (XMLParser, true, ?string).
 */
foreach (['xml_set_object', 'xml_parser_create', 'xml_parse'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $bit = $t . '$' . $p->getName();
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            $bit .= '=' . var_export($p->getDefaultValue(), true);
        } elseif ($p->isOptional()) {
            $bit .= '=?';
        }
        $ps[] = $bit;
    }
    echo $fn, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
$p = xml_parser_create();
echo 'runtime=', get_debug_type($p), "\n";
echo 'named=', xml_set_object(parser: $p, object: new stdClass()) ? 'true' : 'false', "\n";
echo 'parse=', (string) xml_parse(parser: $p, data: '<a/>', is_final: true), "\n";
?>
