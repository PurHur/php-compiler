--TEST--
stdlib xml_parser_free Reflection XMLParser → bool (#27793, ext/xml/xml.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('xml_parser_free');
$p = $r->getParameters()[0];
echo 'parser:', $p->hasType() ? (string) $p->getType() : '(none)', "\n";
echo 'return:', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$parser = xml_parser_create();
echo 'named_free=', var_export(xml_parser_free(parser: $parser), true), "\n";
echo 'still_usable=', (string) xml_parse(parser: $parser, data: '<a/>', is_final: true), "\n";
?>
--EXPECT--
parser:XMLParser
return:bool
named_free=true
still_usable=1
