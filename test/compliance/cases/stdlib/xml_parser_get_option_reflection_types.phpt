--TEST--
stdlib xml_parser_get_option Reflection XMLParser + string|int (#27743, ext/xml/xml.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('xml_parser_get_option');
$p = $r->getParameters()[0];
echo 'parser:', $p->hasType() ? (string) $p->getType() : '(none)', "\n";
echo 'return:', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$parser = xml_parser_create();
xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 1);
echo 'case_folding=', (string) xml_parser_get_option(parser: $parser, option: XML_OPTION_CASE_FOLDING), "\n";
?>
--EXPECT--
parser:XMLParser
return:string|int
case_folding=1
