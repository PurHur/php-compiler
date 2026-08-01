--TEST--
stdlib xml_parser_create_ns/xml_parse_into_struct Reflection XMLParser stubs (#26687, ext/xml/xml.stub.php)
--FILE--
<?php
foreach (['xml_parser_create_ns', 'xml_parse_into_struct'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $ref = $p->isPassedByReference() ? '&' : '';
        $bit = $t . $ref . '$' . $p->getName();
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
$p = xml_parser_create_ns(encoding: null, separator: ':');
echo 'runtime=', get_debug_type($p), "\n";
$vals = [];
$idx = [];
$n = xml_parse_into_struct(parser: $p, data: '<a xmlns="urn:x">v</a>', values: $vals, index: $idx);
echo 'into_struct=', (string) $n, ' tags=', (string) count($vals), "\n";
?>
--EXPECT--
xml_parser_create_ns(?string $encoding=NULL, string $separator=':'): XMLParser
xml_parse_into_struct(XMLParser $parser, string $data, &$values, &$index=NULL): int|false
runtime=XMLParser
into_struct=1 tags=1
