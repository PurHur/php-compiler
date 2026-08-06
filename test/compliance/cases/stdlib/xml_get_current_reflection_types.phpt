--TEST--
stdlib xml_get_current_* Reflection XMLParser stubs (#27738, ext/xml/xml.stub.php)
--FILE--
<?php
foreach ([
    'xml_get_current_byte_index',
    'xml_get_current_column_number',
    'xml_get_current_line_number',
] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[0];
    $t = $p->hasType() ? (string) $p->getType() : '(none)';
    echo $fn, ' ', $p->getName(), ':', $t;
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
$parser = xml_parser_create();
xml_parse(parser: $parser, data: '<a/>', is_final: true);
echo 'named_byte=', (string) xml_get_current_byte_index(parser: $parser), "\n";
?>
--EXPECT--
xml_get_current_byte_index parser:XMLParser: int
xml_get_current_column_number parser:XMLParser: int
xml_get_current_line_number parser:XMLParser: int
named_byte=4
