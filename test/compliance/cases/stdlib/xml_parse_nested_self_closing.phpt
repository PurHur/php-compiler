--TEST--
stdlib xml_parse() nested self-closing child (#12024, ext/xml/xml.c)
--FILE--
<?php
// Fresh parser per document — Expat rejects a second is_final document on the same
// parser (#24647 / php-src XML_Parse). Cases still cover nested self-closing (#12024).
foreach ([
    '<root><item/></root>',
    '<root><a/><b/></root>',
    '<root><item></item></root>',
] as $xml) {
    $parser = xml_parser_create();
    echo (int) xml_parse($parser, $xml, true), "\n";
    xml_parser_free($parser);
}
?>
--EXPECT--
1
1
1
