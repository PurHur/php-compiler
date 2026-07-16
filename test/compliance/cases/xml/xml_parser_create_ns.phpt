--TEST--
ext/xml xml_parser_create_ns() — namespace-expanded element names (#19683, ext/xml/xml.c)
--FILE--
<?php
echo function_exists('xml_parser_create_ns') ? "exists\n" : "missing\n";
function ns_start($parser, $name, $attrs) { echo "S:$name\n"; }
function ns_end($parser, $name) { echo "E:$name\n"; }
$p = xml_parser_create_ns();
xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
xml_set_element_handler($p, 'ns_start', 'ns_end');
xml_parse($p, '<r xmlns:a="urn:a"><a:x/></r>', true);
xml_parser_free($p);
$p2 = xml_parser_create_ns(null, ' ');
xml_parser_set_option($p2, XML_OPTION_CASE_FOLDING, 0);
xml_set_element_handler($p2, 'ns_start', 'ns_end');
xml_parse($p2, '<r xmlns:a="urn:a"><a:x/></r>', true);
xml_parser_free($p2);
echo "done\n";
--EXPECT--
exists
S:r
S:urn:a:x
E:urn:a:x
E:r
S:r
S:urn:a x
E:urn:a x
E:r
done
