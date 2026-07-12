--TEST--
ext/xml xml_set_element_handler() — SAX start/end callbacks (#18203, ext/xml/xml.c)
--FILE--
<?php
function xs($parser, $name, $attrs) { echo "start:$name\n"; }
function xe($parser, $name) { echo "end:$name\n"; }
$p = xml_parser_create();
xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
xml_set_element_handler($p, 'xs', 'xe');
xml_parse($p, '<root><a/></root>', true);
xml_parser_free($p);
echo "done\n";
--EXPECT--
start:root
start:a
end:a
end:root
done
